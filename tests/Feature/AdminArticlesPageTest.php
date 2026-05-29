<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ZipArchive;
use Tests\TestCase;

/**
 * 后台文章页（Blade）最小可用测试：鉴权、列表渲染、创建/编辑页路由。
 */
class AdminArticlesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_articles_page(): void
    {
        $this->get(route('admin.articles.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_articles_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_admin',
            'password' => 'secret-123',
            'email' => 'articles-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee(__('admin.articles.page_title'))
            ->assertViewHas('articles')
            ->assertViewHas('filters');
    }

    public function test_authenticated_admin_can_open_article_create_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_create_admin',
            'password' => 'secret-123',
            'email' => 'articles-create-admin@example.com',
            'display_name' => 'Articles Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee(__('admin.article_create.page_heading'));
    }

    public function test_guest_is_redirected_to_admin_login_when_exporting_articles_archive(): void
    {
        $this->get(route('admin.articles.export'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_export_all_non_deleted_articles_as_zip_markdown_archive(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_export_admin',
            'password' => 'secret-123',
            'email' => 'articles-export-admin@example.com',
            'display_name' => 'Articles Export Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $task = Task::query()->create([
            'name' => '导出测试任务',
        ]);

        Article::query()->create([
            'title' => '已发布文章',
            'slug' => 'published-article',
            'excerpt' => '已发布摘要',
            'content' => "# 已发布文章\n\n这是发布文章内容。",
            'keywords' => 'AI, GEO',
            'meta_description' => '发布文章描述',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'is_hot' => true,
            'is_featured' => false,
            'published_at' => now()->subHour(),
        ]);

        Article::query()->create([
            'title' => '草稿文章',
            'slug' => 'draft-article',
            'excerpt' => '草稿摘要',
            'content' => "## 草稿文章\n\n这是草稿内容。",
            'keywords' => 'Draft',
            'meta_description' => '草稿描述',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 0,
            'is_hot' => false,
            'is_featured' => true,
        ]);

        $trashedArticle = Article::query()->create([
            'title' => '回收站文章',
            'slug' => 'trashed-article',
            'excerpt' => '回收站摘要',
            'content' => '不应该出现在导出里。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'private',
            'review_status' => 'rejected',
        ]);
        $trashedArticle->delete();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $response->assertHeader('content-disposition');

        $tempPath = tempnam(sys_get_temp_dir(), 'articles-export-test-');
        file_put_contents($tempPath, $response->streamedContent());

        $zip = new ZipArchive();
        $openResult = $zip->open($tempPath);

        $this->assertTrue($openResult === true, 'Expected export to be a valid zip archive.');
        $this->assertSame(3, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('manifest.md'));
        $this->assertNotFalse($zip->locateName('articles/已发布文章.md'));
        $this->assertNotFalse($zip->locateName('articles/草稿文章.md'));
        $this->assertFalse($zip->locateName('articles/trashed-article.md'));

        $manifest = $zip->getFromName('manifest.md');
        $publishedContent = $zip->getFromName('articles/已发布文章.md');
        $draftContent = $zip->getFromName('articles/草稿文章.md');

        $zip->close();
        @unlink($tempPath);

        $this->assertIsString($manifest);
        $this->assertIsString($publishedContent);
        $this->assertIsString($draftContent);
        $this->assertStringContainsString('# GEOFlow Article Export', $manifest);
        $this->assertStringContainsString('article_count: 2', $manifest);
        $this->assertStringContainsString('title: 已发布文章', $publishedContent);
        $this->assertStringContainsString('status: published', $publishedContent);
        $this->assertStringContainsString('task: 导出测试任务', $publishedContent);
        $this->assertStringContainsString("### CONTENT\n\n# 已发布文章", $publishedContent);
        $this->assertStringContainsString('title: 草稿文章', $draftContent);
        $this->assertStringContainsString('status: draft', $draftContent);
        $this->assertStringContainsString('is_featured: 1', $draftContent);
    }

    public function test_export_uses_human_readable_title_based_file_names_with_unique_suffixes(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_export_filename_admin',
            'password' => 'secret-123',
            'email' => 'articles-export-filename-admin@example.com',
            'display_name' => 'Articles Export Filename Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '导出分类',
            'slug' => 'export-category',
        ]);
        $author = Author::query()->create([
            'name' => '导出作者',
        ]);

        Article::query()->create([
            'title' => '同名文章',
            'slug' => 'opaque-slug-a',
            'content' => '正文 A',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        Article::query()->create([
            'title' => '同名文章',
            'slug' => 'opaque-slug-b',
            'content' => '正文 B',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');

        $tempPath = tempnam(sys_get_temp_dir(), 'articles-export-title-test-');
        file_put_contents($tempPath, $response->streamedContent());

        $zip = new ZipArchive();
        $openResult = $zip->open($tempPath);

        $this->assertTrue($openResult === true, 'Expected export to be a valid zip archive.');
        $this->assertNotFalse($zip->locateName('articles/同名文章.md'));
        $this->assertNotFalse($zip->locateName('articles/同名文章-2.md'));
        $this->assertFalse($zip->locateName('articles/opaque-slug-a.md'));
        $this->assertFalse($zip->locateName('articles/opaque-slug-b.md'));

        $zip->close();
        @unlink($tempPath);
    }

    public function test_admin_can_save_article_hot_and_featured_flags(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_flags_admin',
            'password' => 'secret-123',
            'email' => 'articles-flags@example.com',
            'display_name' => 'Articles Flags Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => '推荐标记测试文章',
                'excerpt' => '摘要',
                'content' => '正文',
                'keywords' => 'GEO',
                'meta_description' => 'Meta',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'is_hot' => '1',
                'is_featured' => '1',
            ])
            ->assertRedirect();

        $article = Article::query()->where('title', '推荐标记测试文章')->firstOrFail();

        $this->assertTrue((bool) $article->is_hot);
        $this->assertTrue((bool) $article->is_featured);
    }

    public function test_article_list_shows_hot_and_featured_badges(): void
    {
        $admin = Admin::query()->create([
            'username' => 'articles_badges_admin',
            'password' => 'secret-123',
            'email' => 'articles-badges@example.com',
            'display_name' => 'Articles Badges Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '后台标签展示文章',
            'slug' => 'admin-badges-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_hot' => true,
            'is_featured' => true,
            'published_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(__('admin.articles.badge.hot'))
            ->assertSee(__('admin.articles.badge.featured'));
    }

    public function test_admin_brand_stays_geoflow_when_public_site_name_changes(): void
    {
        $admin = Admin::query()->create([
            'username' => 'admin_brand_admin',
            'password' => 'secret-123',
            'email' => 'admin-brand@example.com',
            'display_name' => 'Brand Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => 'Public Frontend Name',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('GEOFlow')
            ->assertDontSee('Public Frontend Name');
    }
}
