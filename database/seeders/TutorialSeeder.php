<?php

namespace Database\Seeders;

use App\Models\Tutorial;
use Illuminate\Database\Seeder;

class TutorialSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->videos() as $video) {
            // Curated links only. Keep admin edits, bookmarks, and older articles on reruns.
            Tutorial::firstOrCreate(['slug' => $video['slug']], $video + [
                'body' => '', 'reading_minutes' => 5, 'featured' => false, 'is_published' => true,
            ]);
        }
    }

    private function videos(): array
    {
        // Original YouTube titles and channels checked on 24 October 2026.
        // Videos remain hosted by their creators; no copies are uploaded to Analista.
        return [
            [
                'slug' => 'thesis-writing-step-by-step',
                'title' => 'How To Write A Dissertation Or Thesis - 8 Step Tutorial + Examples',
                'category' => 'Getting started',
                'youtube_video_id' => '1Ir9z_O4P3A',
                'video_creator' => 'Grad Coach',
                'excerpt' => 'See the whole thesis journey, from choosing a research topic to presenting your findings.',
                'body' => 'Use this overview to make a checklist of the chapters and decisions your thesis will need. Compare the suggested structure with your department’s thesis handbook and discuss your plan with your adviser.',
            ],
            [
                'slug' => 'develop-a-research-question',
                'title' => 'How to Develop a STRONG Research Question | Scribbr 🎓',
                'category' => 'Research questions',
                'youtube_video_id' => '71-GucBaM8U',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Turn a broad interest into a focused question that can guide a manageable research project.',
                'body' => 'After watching, write one research question and identify the people, setting, and evidence it concerns. Check that you can answer it with the time, data, and resources available to your group.',
            ],
            [
                'slug' => 'write-a-thesis-introduction',
                'title' => 'How To Write A Dissertation Introduction Or Thesis Introduction Chapter: 7 Steps + Loads Of Examples',
                'category' => 'Introduction',
                'youtube_video_id' => 'x8mzfnWIoy4',
                'video_creator' => 'Grad Coach',
                'excerpt' => 'Connect your study’s background, research problem, objectives, and significance in a clear introduction.',
                'body' => 'Outline your introduction before drafting it. Explain the context, identify the specific problem, and show how your objectives address that problem. Follow your institution’s required chapter format.',
            ],
            [
                'slug' => 'literature-review-step-by-step',
                'title' => 'How to Write a Literature Review: 3 Minute Step-by-step Guide | Scribbr 🎓',
                'category' => 'Literature review',
                'youtube_video_id' => 'zIYC6zG265E',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Learn how to find sources, identify themes, and organize the ideas behind a literature review.',
                'body' => 'Create a source matrix with each study’s question, method, findings, and limitations. Group related ideas into themes, then explain how the evidence connects to your own research question.',
            ],
            [
                'slug' => 'research-methodology-in-four-steps',
                'title' => 'How to Write a Research Methodology in 4 Steps | Scribbr 🎓',
                'category' => 'Methodology',
                'youtube_video_id' => 'yplWZs3dqNQ',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Explain your research approach and justify how you will collect and analyze your data.',
                'body' => 'Map each question to evidence. Identify the method, participants or data sources, and analysis that will help you answer it. Discuss feasibility and the appropriate research ethics process with your adviser.',
            ],
            [
                'slug' => 'apa-7-in-text-citations',
                'title' => 'APA 7th Edition: The Basics of APA In-text Citations | Scribbr 🎓',
                'category' => 'Citations',
                'youtube_video_id' => 'opp259YvaoE',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Understand the basics of crediting sources within your writing using APA 7.',
                'body' => 'Practice citing a paraphrase and a direct quotation from a source you have read. Check that every in-text citation has a matching reference entry. Use the citation style required by your institution.',
            ],
            [
                'slug' => 'paraphrase-in-five-steps',
                'title' => 'How to Paraphrase in 5 Easy Steps | Scribbr 🎓',
                'category' => 'Academic writing',
                'youtube_video_id' => 'oiM0x0ApVL8',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Explain source material in your own words while preserving its meaning and giving proper credit.',
                'body' => 'Read a short passage, set it aside, and explain its main idea from your understanding. Compare your draft with the original for accuracy and independence of wording, then add the appropriate citation.',
            ],
            [
                'slug' => 'dissertation-results-section',
                'title' => 'How to Write a Dissertation Results Section | Scribbr 🎓',
                'category' => 'Results',
                'youtube_video_id' => 'WP4gDG0q63k',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Present your research findings clearly and organize them around the questions your study asked.',
                'body' => 'Arrange your findings by research question. Choose tables or figures that make the evidence easier to understand and label them clearly. Explain what the data shows before developing your interpretation in the discussion.',
            ],
            [
                'slug' => 'write-a-discussion-section',
                'title' => 'How to Write a Discussion Section | Scribbr 🎓',
                'category' => 'Discussion',
                'youtube_video_id' => 'LfCGoWtF_tc',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Interpret your findings and explain their implications, limitations, and relationship to earlier research.',
                'body' => 'For each main finding, explain what it means for your research question and how it compares with related studies. Keep claims proportional to the evidence and discuss limitations that affect your interpretation.',
            ],
            [
                'slug' => 'dissertation-conclusion',
                'title' => 'How to Write a Conclusion for a Dissertation | Scribbr 🎓',
                'category' => 'Conclusion',
                'youtube_video_id' => 'Wz-J1iPzqTU',
                'video_creator' => 'Scribbr',
                'excerpt' => 'Bring your research question, main findings, and contribution together in a focused closing chapter.',
                'body' => 'Draft a concise answer to your main research question using findings already presented in the thesis. State the contribution of your study and keep recommendations grounded in its results and limitations.',
            ],
        ];
    }
}
