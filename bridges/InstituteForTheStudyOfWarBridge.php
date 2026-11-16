<?php

class InstituteForTheStudyOfWarBridge extends BridgeAbstract
{
    const MAINTAINER = 'sqrtminusone';
    const NAME = 'Institute for the Study of War';
    const URI = 'https://understandingwar.org';

    const CACHE_TIMEOUT = 3600; // 1 hour
    const DESCRIPTION = 'Recent publications of the ISW.';

    // Optional: base URL of a FlareSolverr-backed gateway exposing GET /page?url=… (the site sits behind a
    // Cloudflare challenge). Set in config.ini.php: [InstituteForTheStudyOfWarBridge] gateway = "http://host:8000"
    const CONFIGURATION = [
        'gateway' => [
            'required' => false,
        ],
    ];

    const PARAMETERS = [
        '' => [
            'searchURL' => [
                'name' => 'Filter URL',
                'required' => false,
                'title' => 'Set a filter on https://www.understandingwar.org/research and copy the URL parameters.'
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'required' => true,
                'defaultValue' => 5,
                'exampleValue' => 5
            ]
        ]
    ];

    public function collectData()
    {
        $filter = $this->getInput('searchURL');
        $limit = $this->getInput('limit');
        $html = $this->fetch(self::URI . '/research/?' . $filter);
        // Yes, a typo
        $container = $html->find('div[data-name="reaserach_library"]', 0);
        $entries = $container->find('.research-card-loop-item-3colgrid');

        // FacetWP applies filters in JavaScript, so the fetched HTML is unfiltered. Re-apply the team facet
        // (?_teams=a,b) using the article path, which is /research/<team>/<slug>/.
        parse_str((string) $filter, $query);
        $teams = array_filter(explode(',', $query['_teams'] ?? ''));

        foreach ($entries as $entry) {
            if (count($this->items) >= $limit) {
                break;
            }
            $href = $entry->find('h3.research-card-title a', 0)->href ?? '';
            if ($teams && !preg_match('#/research/(' . implode('|', array_map('preg_quote', $teams)) . ')/#', $href)) {
                continue;
            }
            $this->items[] = $this->processEntry($entry);
        }
    }

    private function fetch(string $url, int $ttl = 0)
    {
        $gateway = $this->getOption('gateway');
        $opts = [];
        if ($gateway) {
            $url = rtrim($gateway, '/') . '/page?url=' . rawurlencode($url);
            // A browser solve takes ~15 s and may wait behind other solves; the global http timeout is 3 s
            $opts = [CURLOPT_TIMEOUT => 90];
        }
        return $ttl ? getSimpleHTMLDOMCached($url, $ttl, [], $opts) : getSimpleHTMLDOM($url, [], $opts);
    }

    private function processEntry($entry)
    {
        $h3 = $entry->find('h3.research-card-title', 0);
        $title = $h3->plaintext;
        $uri = $h3->find('a', 0)->href;

        $date_p = $entry->find('p.research-card-post-date', 0);
        $date = DateTime::createFromFormat('F d, Y', trim($date_p->plaintext));

        $tags = array_map(
            fn($tag) => html_entity_decode($tag->plaintext, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $entry->find('p.tag-cloud-on-cards')
        );

        $item = [
            'uri' => $uri,
            'title' => $title,
            'uid' => $uri,
            'timestamp' => $date->getTimestamp(),
            'categories' => $tags,
        ];

        try {
            $html = $this->fetch($uri, 60 * 60 * 24 * 14);
        } catch (\Exception $e) {
            // Cloudflare sometimes refuses one page; keep the item without its body, the next run retries it
            $this->logger->info(sprintf('ISW: article fetch failed for %s: %s', $uri, $e->getMessage()));
            return $item;
        }
        $content = $html->find('div.dynamic-entry-content', 0);

        $scripts = $content->find('script');
        foreach ($scripts as $script) {
            $script->parent->removeChild($script);
        }
        $item['content'] = $content->innertext;

        return $item;
    }
}
