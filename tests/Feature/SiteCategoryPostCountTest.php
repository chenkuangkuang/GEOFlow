<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteCategoryPostCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_page_shows_the_total_number_of_posts(): void
    {
        $category = Category::query()->create([
            'name' => 'Trader AI',
            'slug' => 'trader-ai',
            'description' => 'AI trading content',
        ]);
        $author = Author::query()->create(['name' => 'GEOFlow']);

        foreach ([
            'AI交易机器人到底是什么？',
            'AI交易机器人怎么选？',
        ] as $index => $title) {
            Article::query()->create([
                'title' => $title,
                'slug' => 'article-'.$index,
                'excerpt' => 'summary',
                'content' => '# Heading',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'published_at' => now()->subDay(),
            ]);
        }

        $response = $this->get('/category/trader-ai');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertTrue(
            str_contains($content, '共 2 篇帖子') || str_contains($content, '2 posts'),
            'Category page should show the total post count.'
        );
    }
}
