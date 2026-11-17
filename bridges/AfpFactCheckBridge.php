<?php

declare(strict_types=1);

class AfpFactCheckBridge extends BridgeAbstract
{
    const NAME = 'AFP Fact Check';
    const URI = 'https://factcheck.afp.com/';
    const DESCRIPTION = 'AFP fact-checks in 26 languages: latest, by topic, by list or region, or by search, with the verdict';
    const MAINTAINER = 'rmdes';
    const CACHE_TIMEOUT = 3600;

    // Optional: base URL of a FlareSolverr-backed gateway exposing GET /page?url=… The sites sit behind Akamai bot
    // management, which refuses plain curl. config.ini.php: [AfpFactCheckBridge] gateway = "http://host:8000"
    const CONFIGURATION = [
        'gateway' => [
            'required' => false,
        ],
    ];

    // One site per language, each with its own (localized) list slugs
    private const LANGUAGES = [
        'English' => 'factcheck.afp.com',
        'Français' => 'factuel.afp.com',
        'Español' => 'factual.afp.com',
        'Català' => 'comprovem.afp.com',
        'Português' => 'checamos.afp.com',
        'Deutsch' => 'faktencheck.afp.com',
        'Nederlands' => 'factchecknederland.afp.com',
        'Suomi' => 'faktantarkistus.afp.com',
        'Svenska' => 'faktakoll.afp.com',
        'Polski' => 'sprawdzam.afp.com',
        'Čeština' => 'napravoumiru.afp.com',
        'Slovenčina' => 'fakty.afp.com',
        'Srpski' => 'cinjenice.afp.com',
        'Hrvatski' => 'provjeracinjenica.afp.com',
        'Română' => 'verificat.afp.com',
        'Magyar' => 'tenykerdes.afp.com',
        'Български' => 'proveri.afp.com',
        'Ελληνικά' => 'factcheckgreek.afp.com',
        'العربية' => 'factcheckarabic.afp.com',
        'Bahasa Indonesia' => 'periksafakta.afp.com',
        'Bahasa Malaysia' => 'semakanfakta.afp.com',
        'हिन्दी' => 'factcheckhindi.afp.com',
        'বাংলা' => 'factcheckbangla.afp.com',
        'မြန်မာ' => 'factcheckmyanmar.afp.com',
        'ภาษาไทย' => 'factcheckthailand.afp.com',
        '한국어' => 'factcheckkorea.afp.com',
    ];

    private const PER_PAGE = 28;

    const PARAMETERS = [
        'global' => [
            'lang' => [
                'name' => 'Language',
                'type' => 'list',
                'values' => self::LANGUAGES,
                'defaultValue' => 'factcheck.afp.com',
            ],
            'limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 15,
                'title' => 'Maximum number of fact-checks (each is one extra page fetch unless "Cards only")',
            ],
            // An opt-out: a global checkbox missing from the request is false (BridgeAbstract ignores its default)
            'cards_only' => [
                'name' => 'Cards only',
                'type' => 'checkbox',
                'title' => 'Skip fetching each fact-check (no verdict, claim or full text; one request per feed)',
            ],
        ],
        'Latest' => [],
        'Topic' => [
            'topic' => [
                'name' => 'Topic',
                'type' => 'list',
                // Topic ids are shared by all language sites
                'values' => [
                    'Health' => '146',
                    'Environment' => '135',
                    'Science' => '222',
                    'Politics' => '134',
                    'Conflicts' => '206',
                    'Society' => '136',
                    'Sport' => '138',
                ],
            ],
        ],
        'List or region' => [
            'path' => [
                'name' => 'Path',
                'required' => true,
                'exampleValue' => '/list/Climate',
                'title' => 'A "Top news" or "Regions" link of the chosen language site, e.g. /list/War-Ukraine, '
                    . '/list/regions/Europe, /list/Guerre-en-Ukraine (French site)',
            ],
        ],
        'Search' => [
            'q' => [
                'name' => 'Keyword',
                'required' => true,
                'exampleValue' => 'ukraine',
            ],
        ],
    ];

    const TEST_DETECT_PARAMETERS = [
        'https://factcheck.afp.com/list' => ['context' => 'Latest', 'lang' => 'factcheck.afp.com'],
        'https://factuel.afp.com/list/topics/135' => ['context' => 'Topic', 'lang' => 'factuel.afp.com', 'topic' => '135'],
        'https://factuel.afp.com/list/Climat' => ['context' => 'List or region', 'lang' => 'factuel.afp.com', 'path' => '/list/Climat'],
        'https://factcheck.afp.com/fact-checking-search-results?search_api_fulltext=ukraine' => [
            'context' => 'Search', 'lang' => 'factcheck.afp.com', 'q' => 'ukraine',
        ],
    ];

    public function collectData()
    {
        $limit = min(100, max(1, (int) $this->getInput('limit')));
        $seen = [];
        // ponytail: at most 4 list pages (112 cards) per feed
        for ($page = 0; $page < 4 && count($this->items) < $limit; $page++) {
            $dom = $this->fetch($this->listUrl($page));
            $cards = $dom->find('main article.node--type-article');
            if (!$cards) {
                break;
            }
            foreach ($cards as $card) {
                $item = $this->parseCard($card);
                if (!$item || isset($seen[$item['uri']])) {
                    continue;
                }
                $seen[$item['uri']] = true;
                $this->items[] = $item;
                if (count($this->items) >= $limit) {
                    break;
                }
            }
            if ($this->queriedContext === 'Search') {
                break; // relevance-ordered: deeper pages are older, not newer
            }
        }

        if (!$this->getInput('cards_only')) {
            foreach ($this->items as &$item) {
                $this->addArticle($item);
            }
        }
    }

    private function parseCard($card): ?array
    {
        $a = $card->find('a[href]', 0);
        if (!$a) {
            return null;
        }
        $uri = urljoin($this->host(), $a->href);
        $heading = $card->find('h2, h3', 0);
        $title = $heading ? $heading->plaintext : ($a->title ?? '');
        $item = [
            'uri' => $uri,
            'uid' => $uri,
            'title' => trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'categories' => [],
            'content' => '',
        ];

        $date = $card->find('.date-short-format', 0);
        $utc = $date ? $date->getAttribute('data-utc-time') : null;
        if ($utc && ctype_digit($utc)) {
            $item['timestamp'] = (int) $utc;
        }
        $tag = $card->find('.tag-like', 0);
        if ($tag && trim($tag->plaintext) !== '') {
            $item['categories'][] = trim(html_entity_decode($tag->plaintext, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $img = $card->find('img', 0);
        if ($img && $img->src) {
            $item['enclosures'] = [urljoin($this->host(), $img->src)];
            $item['content'] = sprintf('<p><img src="%s" alt=""></p>', e($item['enclosures'][0]));
        }
        return $item;
    }

    private function addArticle(array &$item): void
    {
        try {
            $html = $this->fetch($item['uri'], 7 * 86400);
        } catch (\Exception $e) {
            // keep the card-only item; the next run retries (failures are not cached)
            $this->logger->info(sprintf('AFP: article fetch failed for %s: %s', $item['uri'], $e->getMessage()));
            return;
        }

        $review = $this->claimReview($html);
        $verdict = $review['reviewRating']['alternateName'] ?? null;
        $claim = $review['claimReviewed'] ?? null;
        $claimUrl = $review['itemReviewed']['url'] ?? null;
        $claimAuthor = $review['itemReviewed']['author']['name'] ?? null;

        if (!isset($item['timestamp']) && !empty($review['datePublished'])) {
            $item['timestamp'] = strtotime($review['datePublished']) ?: null;
        }
        if ($verdict) {
            $item['categories'][] = $verdict;
        }
        $ogImage = $html->find('meta[property="og:image"]', 0);
        if ($ogImage && $ogImage->content) {
            $item['enclosures'] = [$ogImage->content];
        }

        $content = '';
        foreach ($item['enclosures'] ?? [] as $src) {
            $content .= sprintf('<p><img src="%s" alt=""></p>', e($src));
        }
        if ($verdict) {
            $content .= sprintf('<p><strong>%s</strong></p>', e($verdict));
        }
        if ($claim) {
            $source = $claimUrl ? sprintf(' (<a href="%s">%s</a>)', e($claimUrl), e($claimAuthor ?: $claimUrl)) : '';
            $content .= sprintf('<blockquote>%s%s</blockquote>', e($claim), $source);
        }
        // Header blocks (search bar, dark-mode switch) are field--name-body too: scope to the article node
        $body = $html->find('article.node--view-mode-full .field--name-body', 0);
        if ($body) {
            foreach ($body->find('script') as $script) {
                $script->outertext = '';
            }
            $content .= defaultLinkTo($body->innertext, $this->host());
        }
        $item['content'] = $content;
    }

    // The schema.org ClaimReview node of an article's JSON-LD, or []
    private function claimReview($html): array
    {
        foreach ($html->find('script[type="application/ld+json"]') as $script) {
            $data = json_decode(html_entity_decode($script->innertext, ENT_QUOTES, 'UTF-8'), true);
            foreach ($data['@graph'] ?? [$data] as $node) {
                if (($node['@type'] ?? null) === 'ClaimReview') {
                    return $node;
                }
            }
        }
        return [];
    }

    private function fetch(string $url, int $ttl = 0)
    {
        $gateway = $this->getOption('gateway');
        $opts = [];
        if ($gateway) {
            $url = rtrim($gateway, '/') . '/page?url=' . rawurlencode($url);
            // A browser solve can take ~15 s and wait behind other solves; the global http timeout is 3 s
            $opts = [CURLOPT_TIMEOUT => 90];
        }
        return $ttl ? getSimpleHTMLDOMCached($url, $ttl, [], $opts) : getSimpleHTMLDOM($url, [], $opts);
    }

    private function host(): string
    {
        $host = $this->getInput('lang');
        return 'https://' . (in_array($host, self::LANGUAGES, true) ? $host : 'factcheck.afp.com') . '/';
    }

    private function listUrl(int $page = 0): string
    {
        $query = $page > 0 ? ['page' => $page] : [];
        switch ($this->queriedContext) {
            case 'Topic':
                $path = 'list/topics/' . (int) $this->getInput('topic');
                break;
            case 'List or region':
                // Accept a full URL or a path; keep only the path, so the host always comes from the language
                $path = ltrim((string) parse_url((string) $this->getInput('path'), PHP_URL_PATH), '/');
                if (!preg_match('#^list(/[\w%.-]+)*$#u', $path)) {
                    throwClientException('Path must be a list link of the site, e.g. /list/Climate');
                }
                break;
            case 'Search':
                $path = 'fact-checking-search-results';
                $query = ['search_api_fulltext' => $this->getInput('q')] + $query;
                break;
            default:
                $path = 'list';
        }
        return $this->host() . $path . ($query ? '?' . http_build_query($query) : '');
    }

    public function getURI()
    {
        return $this->queriedContext ? $this->listUrl() : parent::getURI();
    }

    public function getName()
    {
        if (!$this->queriedContext) {
            return parent::getName();
        }
        $lang = $this->getKey('lang') ?? '';
        switch ($this->queriedContext) {
            case 'Topic':
                $what = $this->getKey('topic');
                break;
            case 'List or region':
                $what = urldecode(basename((string) parse_url((string) $this->getInput('path'), PHP_URL_PATH)));
                break;
            case 'Search':
                $what = '"' . $this->getInput('q') . '"';
                break;
            default:
                $what = 'Latest';
        }
        return sprintf('AFP Fact Check (%s) – %s', $lang, $what);
    }

    public function detectParameters($url)
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (!in_array($host, self::LANGUAGES, true)) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path === '' || $path === '/list') {
            return ['context' => 'Latest', 'lang' => $host];
        }
        if (preg_match('#^/list/topics/(\d+)$#', $path, $m) && in_array($m[1], self::PARAMETERS['Topic']['topic']['values'], true)) {
            return ['context' => 'Topic', 'lang' => $host, 'topic' => $m[1]];
        }
        if (str_starts_with($path, '/list/')) {
            return ['context' => 'List or region', 'lang' => $host, 'path' => $path];
        }
        parse_str($parts['query'] ?? '', $query);
        if ($path === '/fact-checking-search-results' && !empty($query['search_api_fulltext'])) {
            return ['context' => 'Search', 'lang' => $host, 'q' => $query['search_api_fulltext']];
        }
        return null;
    }
}
