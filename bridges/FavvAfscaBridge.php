<?php

declare(strict_types=1);

class FavvAfscaBridge extends BridgeAbstract
{
    const NAME = 'AFSCA/FAVV – Rappels de produits';
    const URI = 'https://favv-afsca.be/';
    const DESCRIPTION = 'Product recalls and warnings from the Belgian Federal Agency for the Safety of the Food Chain';
    const MAINTAINER = 'rmdes';
    const CACHE_TIMEOUT = 3600;
    const PARAMETERS = [[
        'lang' => [
            'name' => 'Language',
            'type' => 'list',
            'values' => [
                'Français' => 'fr/produits',
                'Nederlands' => 'nl/producten',
            ],
        ],
        'type' => [
            'name' => 'Issue type',
            'type' => 'list',
            'values' => [
                'All' => 'all',
                'Rappel / Terugroeping' => '13',
                'Avertissement / Waarschuwing' => '14',
                'Autre / Andere' => '4302',
            ],
        ],
        'q' => [
            'name' => 'Keyword',
            'exampleValue' => 'jumbo',
            'title' => 'Full-text search (brand, product, allergen…)',
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'defaultValue' => 10,
            'title' => 'Maximum number of items (10 per page fetched)',
        ],
    ]];

    // Detail pages of published recalls rarely change
    private const DETAIL_TTL = 7 * 86400;

    public function collectData()
    {
        $limit = max(1, (int) $this->getInput('limit'));
        // ponytail: hard page cap so a huge limit can't hammer the site
        for ($page = 0; $page < 10 && count($this->items) < $limit; $page++) {
            $dom = getSimpleHTMLDOM($this->listUrl($page));
            $cards = $dom->find('li.view__row a.node__card-link');
            if (!$cards) {
                break;
            }
            foreach ($cards as $card) {
                if (count($this->items) >= $limit) {
                    break;
                }
                $this->items[] = $this->parseCard($card);
            }
        }
    }

    private function parseCard($card): array
    {
        $uri = urljoin(self::URI, $card->href);
        $type = $this->text($card->find('.node__issue-type .taxonomy-term__title', 0));
        $products = array_map(fn ($e) => $this->text($e), $card->find('.node__initiators .initiator'));
        $reason = $this->text($card->find('.node__reasons .reason', 0));
        $heading = $this->text($card->find('.node__title', 0));
        $img = $card->find('.node__image img', 0);

        $item = [
            'uri' => $uri,
            'uid' => $uri,
            'title' => ($type ? $type . ': ' : '') . ($products ? implode(', ', $products) : $heading),
            'categories' => $type ? [$type] : [],
            'enclosures' => $img ? [urljoin(self::URI, $img->src)] : [],
        ];

        $time = $card->find('time.node__issue-date', 0);
        // dd/mm/YYYY: strtotime() would read it as US month-first
        $date = $time ? DateTime::createFromFormat('!d/m/Y', trim($time->datetime), new DateTimeZone('Europe/Brussels')) : false;
        if ($date) {
            $item['timestamp'] = $date->getTimestamp();
        }

        $body = '';
        try {
            $detail = getSimpleHTMLDOMCached($uri, self::DETAIL_TTL);
            $images = $detail->find('.node__product-images img');
            if ($images) {
                $item['enclosures'] = array_map(fn ($i) => urljoin(self::URI, $i->src), $images);
            }
            $bodyNode = $detail->find('.field--name-body', 0);
            if ($bodyNode) {
                $body = defaultLinkTo($bodyNode->innertext, self::URI);
            }
        } catch (\Exception $e) {
            // Keep the card-only item rather than dropping it
            $this->logger->info(sprintf('FavvAfsca: detail fetch failed for %s: %s', $uri, $e->getMessage()));
        }

        $content = '';
        foreach ($item['enclosures'] as $src) {
            $content .= sprintf('<p><img src="%s" alt=""></p>', e($src));
        }
        $content .= sprintf('<p><strong>%s</strong></p>', e($heading));
        if ($reason) {
            $content .= sprintf('<p><em>%s</em></p>', e($reason));
        }
        $item['content'] = $content . $body;

        return $item;
    }

    // simplehtmldom plaintext keeps entities (&amp;), which formats would escape again
    private function text($node): string
    {
        return $node ? trim(html_entity_decode($node->plaintext, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private function listUrl(int $page = 0): string
    {
        $query = [];
        $type = $this->getInput('type');
        if ($type && $type !== 'all') {
            $query['f'] = ['products_issue_type:' . $type];
        }
        if ($this->getInput('q')) {
            $query['search_api_fulltext'] = $this->getInput('q');
        }
        if ($page > 0) {
            $query['page'] = $page;
        }
        return self::URI . $this->getInput('lang') . ($query ? '?' . http_build_query($query) : '');
    }

    public function getURI()
    {
        return $this->getInput('lang') ? $this->listUrl() : parent::getURI();
    }

    public function getName()
    {
        if (!$this->getInput('lang')) {
            return parent::getName();
        }
        $parts = array_filter([
            $this->getInput('type') !== 'all' ? $this->getKey('type') : null,
            $this->getInput('q'),
        ]);
        return parent::getName() . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }
}
