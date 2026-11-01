<?php

namespace Tests\Feature;

use App\Models\DirectConversation;
use App\Models\DirectMessage;
use App\Models\DirectMessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DirectMessageAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_an_attachment_only_message_creates_a_private_conversation_and_uses_server_owned_metadata(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $file = $this->image('architecture.png', 48, 32);

        $this->actingAs($sender)->postJson(route('messages.send', $recipient), [
            'attachments' => [$file],
            'sender_id' => $recipient->id,
            'attachment_path' => 'public/forged.png',
            'mime_type' => 'text/html',
        ])->assertCreated()->assertJsonPath('redirect', route('messages.show', 1));

        $conversation = DirectConversation::sole();
        $message = DirectMessage::sole();
        $attachment = DirectMessageAttachment::sole();
        $this->assertSame($conversation->id, $message->direct_conversation_id);
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertSame($recipient->id, $message->recipient_id);
        $this->assertSame('', (string) $message->body);
        $this->assertNull($message->read_at);
        $this->assertSame('text', $message->kind);
        $this->assertSame('architecture.png', $attachment->name);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame(filesize($file->getPathname()), $attachment->size);
        $this->assertSame($message->id, $attachment->direct_message_id);
        $this->assertStringStartsWith('message-attachments/'.$conversation->id.'/'.$message->id.'/', $attachment->path);
        $this->assertStringNotContainsString($attachment->name, $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertArrayNotHasKey('path', $attachment->toArray());
        $this->assertDatabaseCount('direct_conversations', 1);
        $this->assertDatabaseCount('direct_messages', 1);
        $this->assertDatabaseCount('activities', 0);
    }

    public function test_replies_and_polling_include_all_attachments_without_exposing_storage_or_account_fields(): void
    {
        [$sender, $recipient, $conversation] = $this->participants();
        $reply = $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'body' => '  Here are the diagram and notes.  ',
            'attachments' => [
                $this->image('diagram.jpg', 40, 30),
                UploadedFile::fake()->createWithContent('review-notes.txt', "Review the database schema.\nCheck unique indexes.\n"),
            ],
            'attachments_metadata' => [['id' => 999, 'path' => 'public/forged', 'url' => 'https://untrusted.example/file']],
        ])->assertCreated()->assertJsonPath('message.body', 'Here are the diagram and notes.')
            ->assertJsonCount(2, 'message.attachments');

        $payload = $reply->json('message.attachments');
        $this->assertSame(['diagram.jpg', 'review-notes.txt'], array_column($payload, 'name'));
        foreach ($payload as $index => $item) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'size', 'mime_type', 'is_image', 'url', 'download_url'], array_keys($item));
            $this->assertSame($index === 0, $item['is_image']);
            $this->assertSame(route('messages.attachments.show', $item['id']), $item['url']);
            $this->assertSame(route('messages.attachments.show', ['attachment' => $item['id'], 'download' => 1]), $item['download_url']);
        }
        $message = DirectMessage::sole();
        $this->assertNull($message->read_at);
        $poll = $this->actingAs($recipient)->getJson(route('messages.updates', ['conversation' => $conversation, 'after' => 0]))
            ->assertOk()->assertJsonPath('messages.0.attachments', $payload)
            ->assertJsonPath('messages.0.is_own', false)->assertJsonPath('unread_count', 0);
        $this->assertNotNull($message->fresh()->read_at);
        foreach (DirectMessageAttachment::all() as $attachment) {
            $this->assertStringNotContainsString($attachment->path, $reply->getContent());
            $this->assertStringNotContainsString($attachment->path, $poll->getContent());
        }
        foreach (['password', 'remember_token', 'attachment_path', 'https://untrusted.example/file'] as $private) {
            $this->assertStringNotContainsString($private, $poll->getContent());
        }
        $this->assertDatabaseCount('direct_conversations', 1);
        $this->assertDatabaseCount('direct_message_attachments', 2);
    }

    public function test_attachment_only_inbox_previews_are_readable_and_do_not_mark_the_message_read(): void
    {
        [$sender, $recipient, $conversation] = $this->participants();
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'attachments' => [$this->image('campus-diagram.png', 20, 20)],
        ])->assertCreated();
        $message = DirectMessage::sole();
        $attachment = DirectMessageAttachment::sole();

        $response = $this->actingAs($recipient)->getJson(route('messages.inbox-updates'))->assertOk()
            ->assertJsonPath('unread_count', 1)->assertJsonPath('conversations.0.unread_count', 1);
        $this->assertNotSame('', trim($response->json('conversations.0.preview')));
        $this->assertStringNotContainsString($attachment->path, $response->getContent());
        $this->assertNull($message->fresh()->read_at);
        $this->get(route('messages.index', ['list' => 1]))->assertOk();
        $this->assertNull($message->fresh()->read_at);
    }

    public function test_composer_accepts_multiple_files_and_initial_html_escapes_attachment_names(): void
    {
        [$sender, $recipient, $conversation] = $this->participants();
        $name = 'notes <img src=x onerror=alert(1)> & evidence.txt';
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'body' => 'A file for discussion.',
            'attachments' => [UploadedFile::fake()->createWithContent($name, 'Please check the methodology section.')],
        ])->assertCreated();
        $attachment = DirectMessageAttachment::sole();

        $response = $this->actingAs($recipient)->get(route('messages.show', $conversation))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//form[@data-message-form]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        $files = $xpath->query('.//input[@type="file" and @name="attachments[]" and @multiple]', $form);
        $this->assertSame(1, $files->length);
        $this->assertStringContainsString('.pdf', $files->item(0)->getAttribute('accept'));
        $this->assertSame(0, $xpath->query('.//textarea[@name="body" and @required]', $form)->length);
        $response->assertSee(e($attachment->name), false)->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee(e($attachment->download_url), false)->assertDontSee($attachment->path, false);
    }

    public function test_attachments_are_accessible_only_to_active_conversation_participants(): void
    {
        [$sender, $recipient, $conversation] = $this->participants();
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'attachments' => [$this->image('private.png')],
        ])->assertCreated();
        $attachment = DirectMessageAttachment::sole();
        $url = route('messages.attachments.show', $attachment);

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $outsider) {
            $this->actingAs($outsider)->get($url)->assertForbidden();
            $this->get($url.'?download=1')->assertForbidden();
        }
        $this->actingAs($sender)->get($url)->assertOk();
        $this->actingAs($recipient)->get($url)->assertOk();
        $recipient->update(['is_suspended' => true]);
        $this->actingAs($recipient->fresh())->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(DirectMessage::sole()->read_at);
    }

    public function test_inline_images_and_document_downloads_have_safe_private_response_headers(): void
    {
        [$sender, , $conversation] = $this->participants();
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'attachments' => [$this->image('diagram.png'), $this->pdf()],
        ])->assertCreated();
        $image = DirectMessageAttachment::where('name', 'diagram.png')->sole();
        $pdf = DirectMessageAttachment::where('name', 'chapter.pdf')->sole();

        $inline = $this->get($image->url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $inline->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $inline->headers->get('Cache-Control'));
        $this->get($image->download_url)->assertOk()->assertDownload('diagram.png');
        $this->get($pdf->url)->assertOk()->assertDownload('chapter.pdf')
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_disguised_or_unsupported_files_and_empty_uploads_leave_no_message_or_files(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $invalid = [
            ['diagram.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            ['page.html', '<!doctype html><html><body>Not an attachment</body></html>'],
            ['diagram.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
            ['chapter.pdf', '<html><script>alert(1)</script></html>'],
            ['notes.txt', '<?php echo "code"; ?>'],
            ['binary.txt', "Research notes.\x00\xff\x01"],
            ['binary.csv', "test,result\nfirst,\x00\xff\n"],
            ['markup.txt', '<html><script>alert(1)</script></html>'],
            ['malware.exe', "MZ\x00\x01\x02\x03"],
            ['empty.txt', ''],
            ['diagram.jpg', $this->image('actual.png')->getContent()],
        ];
        foreach ($invalid as [$name, $content]) {
            $this->actingAs($sender)->postJson(route('messages.send', $recipient), [
                'body' => 'This invalid upload should not send.',
                'attachments' => [UploadedFile::fake()->createWithContent($name, $content)],
            ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        }
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('direct_message_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_file_count_per_file_size_and_combined_size_limits_are_enforced_before_persistence(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $this->actingAs($sender)->postJson(route('messages.send', $recipient), [
            'attachments' => array_map(fn ($index) => UploadedFile::fake()->createWithContent('note-'.$index.'.txt', 'Research notes.'), range(1, 6)),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson(route('messages.send', $recipient), [
            'attachments' => [UploadedFile::fake()->createWithContent('too-large.txt', str_repeat('a', 10 * 1024 * 1024 + 1))],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $content = str_repeat("Research notes.\n", 7 * 1024 * 1024 / 16);
        $this->postJson(route('messages.send', $recipient), [
            'attachments' => array_map(fn ($index) => UploadedFile::fake()->createWithContent('combined-'.$index.'.txt', $content), range(1, 3)),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson(route('messages.send', $recipient), ['attachments' => 'forged metadata'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson(route('messages.send', $recipient), ['body' => '   ', 'attachments' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('direct_message_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_supported_image_and_document_formats_remain_usable(): void
    {
        [$sender, , $conversation] = $this->participants();
        $images = array_map(fn ($extension) => $this->image('image.'.$extension, 24, 16), ['jpg', 'png', 'gif', 'webp']);
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), ['attachments' => $images])
            ->assertCreated()->assertJsonCount(4, 'message.attachments');
        $this->postJson(route('messages.store', $conversation), [
            'attachments' => [
                $this->pdf(),
                UploadedFile::fake()->createWithContent('notes.txt', "Methodology notes.\nReview the conclusion.\n"),
                UploadedFile::fake()->createWithContent('results.csv', "test,count\nsuccess,42\nfailure,2\n"),
                $this->archive('references.zip', ['references/readme.txt' => 'Reference notes.']),
                UploadedFile::fake()->createWithContent('long-notes.txt', str_repeat("Research notes.\n", 7 * 1024 * 1024 / 16)),
            ],
        ])->assertCreated()->assertJsonCount(5, 'message.attachments');
        $this->postJson(route('messages.store', $conversation), [
            'attachments' => [
                $this->office('chapter.docx', 'word/document.xml'),
                $this->office('results.xlsx', 'xl/workbook.xml'),
                $this->office('slides.pptx', 'ppt/presentation.xml'),
            ],
        ])->assertCreated()->assertJsonCount(3, 'message.attachments');
        $this->assertDatabaseCount('direct_messages', 3);
        $this->assertDatabaseCount('direct_message_attachments', 12);
        $this->assertSame('text/plain', DirectMessageAttachment::where('name', 'long-notes.txt')->sole()->mime_type);
        foreach (DirectMessageAttachment::all() as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_missing_files_and_tampered_private_paths_or_types_are_not_served(): void
    {
        [$sender, , $conversation] = $this->participants();
        $this->actingAs($sender)->postJson(route('messages.store', $conversation), [
            'attachments' => [$this->image('diagram.png')],
        ])->assertCreated();
        $attachment = DirectMessageAttachment::sole();
        $path = $attachment->path;
        foreach (['../secret.png', 'public/diagram.png', 'https://untrusted.example/diagram.png'] as $tampered) {
            $attachment->update(['path' => $tampered]);
            $this->get($attachment->url)->assertNotFound();
        }
        $attachment->update(['path' => $path, 'mime_type' => 'text/html']);
        $this->get($attachment->url)->assertNotFound();
        $attachment->update(['mime_type' => 'image/png']);
        Storage::disk('local')->delete($path);
        $this->get($attachment->url)->assertNotFound();
        $this->get(route('messages.attachments.show', 999999))->assertNotFound();
    }

    public function test_oversized_image_dimensions_and_disguised_office_archives_are_rejected(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $invalid = [
            $this->image('wide.png', 8193, 1),
            $this->archive('renamed.docx', ['readme.txt' => 'This is not a Word document.']),
            $this->archive('macro.docx', [
                '[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<document/>',
                'word/vbaProject.bin' => 'Untrusted macro content.',
            ]),
            $this->archive('traversal.zip', ['../outside.txt' => 'An unsafe archive entry.']),
        ];
        foreach ($invalid as $file) {
            $this->actingAs($sender)->postJson(route('messages.send', $recipient), ['attachments' => [$file]])
                ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        }
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_database_failure_removes_files_and_rolls_back_the_entire_new_conversation(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $attempted = 0;
        $event = 'eloquent.creating: '.DirectMessageAttachment::class;
        Event::listen($event, function () use (&$attempted) {
            if (++$attempted === 2) {
                throw new \RuntimeException('Simulated attachment database failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($sender)->postJson(route('messages.send', $recipient), [
                'body' => 'This entire send must roll back.',
                'attachments' => [$this->image('first.png'), $this->image('second.png')],
            ]);
            $this->fail('The simulated attachment failure should escape.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated attachment database failure.', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(2, $attempted);
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('direct_message_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_storage_failure_after_one_file_removes_partial_uploads_and_does_not_send_the_message(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $disk = Storage::disk('local');
        $attempted = 0;
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($disk, &$attempted) {
            return ++$attempted === 1 ? $disk->putFileAs(...$arguments) : false;
        });
        Storage::set('local', $failingDisk);

        $this->actingAs($sender)->postJson(route('messages.send', $recipient), [
            'attachments' => [$this->image('first.png'), $this->image('second.png')],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.1');

        $this->assertSame(2, $attempted);
        $this->assertDatabaseCount('direct_conversations', 0);
        $this->assertDatabaseCount('direct_messages', 0);
        $this->assertDatabaseCount('direct_message_attachments', 0);
        $this->assertSame([], $disk->allFiles());
    }

    private function participants(): array
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->evaluator()->create();
        $conversation = DirectConversation::create([
            'lower_user_id' => min($sender->id, $recipient->id),
            'higher_user_id' => max($sender->id, $recipient->id),
            'last_message_at' => now(),
        ]);

        return [$sender, $recipient, $conversation];
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('chapter.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    private function office(string $name, string $document): UploadedFile
    {
        return $this->archive($name, [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            $document => '<?xml version="1.0"?><document/>',
        ]);
    }

    private function archive(string $name, array $entries): UploadedFile
    {
        // Tiny uncompressed fixtures keep these tests usable without PHP's optional ZIP extension.
        $local = '';
        $central = '';
        foreach ($entries as $entry => $content) {
            $offset = strlen($local);
            $size = strlen($content);
            $crc = crc32($content);
            $local .= "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $size, $size, strlen($entry), 0).$entry.$content;
            $central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, $size, $size, strlen($entry), 0, 0, 0, 0, 0, $offset).$entry;
        }
        $end = "PK\x05\x06".pack('vvvvVVv', 0, 0, count($entries), count($entries), strlen($central), strlen($local), 0);

        return UploadedFile::fake()->createWithContent($name, $local.$central.$end);
    }

    private function image(string $name, int $width = 2, int $height = 2): UploadedFile
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            $chunk = fn ($type, $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
            $content = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
                .$chunk('IDAT', gzcompress(str_repeat("\0".str_repeat("\0", $width * 3), $height))).$chunk('IEND', '');
        } else {
            $content = base64_decode(match ($extension) {
                'jpg', 'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAb/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=',
                'gif' => 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
                'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoCAAIAAUAmJaQAA3AA/v02aAA=',
            });
        }

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}
