# Thesis tutorial videos

Ten original YouTube tutorials curated for Analista. Titles, channels, and JPEG thumbnails were checked against YouTube oEmbed and image endpoints on 2026-10-24. Video playback and embedding availability remain controlled by YouTube and the original creators.

The app loads a privacy-enhanced YouTube player when a visitor presses Play, and provides an original YouTube link on every video page. No durations are guessed.

| Topic order | Original video | Creator |
| --- | --- | --- |
| 1 | [How To Write A Dissertation Or Thesis - 8 Step Tutorial + Examples](https://www.youtube.com/watch?v=1Ir9z_O4P3A) | Grad Coach |
| 2 | [How to Develop a STRONG Research Question \| Scribbr 🎓](https://www.youtube.com/watch?v=71-GucBaM8U) | Scribbr |
| 3 | [How To Write A Dissertation Introduction Or Thesis Introduction Chapter: 7 Steps + Loads Of Examples](https://www.youtube.com/watch?v=x8mzfnWIoy4) | Grad Coach |
| 4 | [How to Write a Literature Review: 3 Minute Step-by-step Guide \| Scribbr 🎓](https://www.youtube.com/watch?v=zIYC6zG265E) | Scribbr |
| 5 | [How to Write a Research Methodology in 4 Steps \| Scribbr 🎓](https://www.youtube.com/watch?v=yplWZs3dqNQ) | Scribbr |
| 6 | [APA 7th Edition: The Basics of APA In-text Citations \| Scribbr 🎓](https://www.youtube.com/watch?v=opp259YvaoE) | Scribbr |
| 7 | [How to Paraphrase in 5 Easy Steps \| Scribbr 🎓](https://www.youtube.com/watch?v=oiM0x0ApVL8) | Scribbr |
| 8 | [How to Write a Dissertation Results Section \| Scribbr 🎓](https://www.youtube.com/watch?v=WP4gDG0q63k) | Scribbr |
| 9 | [How to Write a Discussion Section \| Scribbr 🎓](https://www.youtube.com/watch?v=LfCGoWtF_tc) | Scribbr |
| 10 | [How to Write a Conclusion for a Dissertation \| Scribbr 🎓](https://www.youtube.com/watch?v=Wz-J1iPzqTU) | Scribbr |

The layout was adapted from [Bryl Lim?s blog](https://www.bryllim.com/blog): thumbnail rows, a small pixel heading, list/grid controls, and thin separators. Analista retains its shared navigation and Geist fonts.

Run `php artisan migrate` and `php artisan db:seed --class=TutorialSeeder` to install the video columns and initial collection. The content seeder preserves existing editorial changes and written guides. Public discovery shows published videos; existing written guide links and bookmarks continue to work.
