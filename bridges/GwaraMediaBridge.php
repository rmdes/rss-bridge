<?php

declare(strict_types=1);

class GwaraMediaBridge extends BridgeAbstract
{
    const NAME = 'Gwara Media';
    const URI = 'https://gwaramedia.com/';
    const DESCRIPTION = 'News from Gwara Media (Kharkiv), with featured image, author, categories and tags';
    const MAINTAINER = 'rmdes';
    const CACHE_TIMEOUT = 1800;
    const PARAMETERS = [[
        'lang' => [
            'name' => 'Language',
            'type' => 'list',
            'values' => [
                'English' => 'en',
                'Українська' => 'uk',
            ],
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 10,
            'title' => 'Maximum number of items (1-100)',
        ],
    ]];

    public function collectData()
    {
        // WordPress REST API: one request gives content, featured image, author and terms (Polylang adds ?lang=)
        $url = self::URI . 'wp-json/wp/v2/posts?' . http_build_query([
            'lang' => $this->getInput('lang'),
            'per_page' => min(100, max(1, (int) $this->getInput('limit'))),
            '_embed' => 'author,wp:featuredmedia,wp:term',
        ]);
        $posts = Json::decode(getContents($url));

        foreach ($posts as $post) {
            $embedded = $post['_embedded'] ?? [];
            $item = [
                'uri' => $post['link'],
                'uid' => $post['link'],
                'title' => html_entity_decode($post['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'timestamp' => strtotime($post['date_gmt'] . 'Z'),
                'author' => $embedded['author'][0]['name'] ?? null,
                'categories' => [],
                'content' => $post['content']['rendered'],
            ];

            foreach ($embedded['wp:term'] ?? [] as $terms) {
                foreach ($terms as $term) {
                    if (in_array($term['taxonomy'] ?? '', ['category', 'post_tag'], true)) {
                        $item['categories'][] = html_entity_decode($term['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }
            }

            $image = $embedded['wp:featuredmedia'][0]['source_url'] ?? null;
            if ($image) {
                $item['enclosures'] = [$image];
                $alt = $embedded['wp:featuredmedia'][0]['alt_text'] ?? '';
                $item['content'] = sprintf('<p><img src="%s" alt="%s"></p>', e($image), e($alt)) . $item['content'];
            }

            $this->items[] = $item;
        }
    }

    public function getURI()
    {
        $lang = $this->getInput('lang');
        return $lang === 'en' ? self::URI . 'en/' : self::URI;
    }
}
