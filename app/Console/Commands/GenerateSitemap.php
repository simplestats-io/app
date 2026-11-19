<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JCFrane\MdBlog\Facades\MdBlog;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

class GenerateSitemap extends Command
{
    protected $signature = 'app:generate-sitemap';

    protected $description = 'Generate sitemap.xml with localized public routes';

    /** @var string[] */
    private array $publicPaths = [
        '/',
        '/imprint',
    ];

    /**
     * VitePress demo page that is built but not linked from the docs navigation.
     *
     * @var string[]
     */
    private array $excludedDocs = [
        'markdown-examples',
    ];

    public function handle(): int
    {
        $baseUrl = rtrim(config('app.url'), '/');
        $supported = array_keys(config('locales.supported', []));
        $default = config('locales.default', 'en');

        $sitemap = Sitemap::create();

        foreach ($this->publicPaths as $path) {
            $defaultUrl = $baseUrl.$path;

            $url = Url::create($defaultUrl)
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority($path === '/' ? 1.0 : 0.8);

            foreach ($supported as $locale) {
                if ($locale === $default) {
                    $url->addAlternate($defaultUrl, $locale);
                } else {
                    $localizedPath = '/'.$locale.($path === '/' ? '' : $path);
                    $url->addAlternate($baseUrl.$localizedPath, $locale);
                }
            }

            $url->addAlternate($defaultUrl, 'x-default');

            $sitemap->add($url);

            foreach ($supported as $locale) {
                if ($locale === $default) {
                    continue;
                }

                $localizedPath = '/'.$locale.($path === '/' ? '' : $path);
                $localizedUrl = Url::create($baseUrl.$localizedPath)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                    ->setPriority($path === '/' ? 0.9 : 0.7);

                foreach ($supported as $altLocale) {
                    if ($altLocale === $default) {
                        $localizedUrl->addAlternate($defaultUrl, $altLocale);
                    } else {
                        $altPath = '/'.$altLocale.($path === '/' ? '' : $path);
                        $localizedUrl->addAlternate($baseUrl.$altPath, $altLocale);
                    }
                }

                $localizedUrl->addAlternate($defaultUrl, 'x-default');

                $sitemap->add($localizedUrl);
            }
        }

        $sitemap->add(
            Url::create($baseUrl.'/blog')
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(0.8)
        );

        $sitemap->add(
            Url::create($baseUrl.'/changelog')
                ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                ->setPriority(0.5)
        );

        foreach (MdBlog::latest() as $post) {
            $sitemap->add(
                Url::create($baseUrl.'/blog/'.$post->slug)
                    ->setLastModificationDate($post->date)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_MONTHLY)
                    ->setPriority(0.7)
            );
        }

        foreach ($this->docsPaths() as $path) {
            $sitemap->add(
                Url::create($baseUrl.$path)
                    ->setChangeFrequency(Url::CHANGE_FREQUENCY_WEEKLY)
                    ->setPriority($path === '/docs/' ? 0.8 : 0.6)
            );
        }

        $sitemap->writeToFile(public_path('sitemap.xml'));

        $this->info('Sitemap generated at public/sitemap.xml');

        return self::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function docsPaths(): array
    {
        return collect(File::files(base_path('docs')))
            ->filter(fn ($file): bool => $file->getExtension() === 'md')
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->reject(fn (string $name): bool => in_array($name, $this->excludedDocs, true))
            ->map(fn (string $name): string => $name === 'index' ? '/docs/' : '/docs/'.$name.'.html')
            ->sort()
            ->values()
            ->all();
    }
}
