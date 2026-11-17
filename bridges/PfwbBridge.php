<?php

declare(strict_types=1);

class PfwbBridge extends BridgeAbstract
{
    const NAME = 'Parlement de la Fédération Wallonie-Bruxelles';
    const URI = 'https://www.pfwb.be/';
    const DESCRIPTION = 'News, agenda and parliamentary documents (questions, decrees, adopted texts, reports…) of the PFWB';
    const MAINTAINER = 'rmdes';
    const CACHE_TIMEOUT = 3600;

    // Groups of the site's document_type facet values (matched exactly, OR-ed together)
    private const DOCUMENT_GROUPS = [
        'questions-ecrites' => ['Question écrite'],
        'questions-orales' => ['Question orale'],
        'questions-actualite' => ["Question d'actualité"],
        'interpellations' => ['Interpellation'],
        'decrets' => ['projet de décret', 'proposition de décret', 'projet de décret budgétaire'],
        'resolutions' => ['proposition de résolution'],
        'textes-adoptes' => ['Texte adopté en séance plénière', 'Texte adopté en commission'],
        'comptes-rendus' => ['Compte Rendu Intégral (CRI) séance plénière', 'Compte rendu intégral (CRIC) Commission'],
        'rapports' => ['Rapport de Commission', 'Rapport'],
    ];

    // Groups of the agenda's event_type facet values
    private const EVENT_GROUPS = [
        'pleniere' => ['Séance Plénière'],
        'commissions' => [
            'Commission permanente', 'Commission spéciale', 'Commission de coopération', 'Commission non permanente',
            'Commission plénière', 'Commission des poursuites', 'Commission interparlementaire', "Comité d'avis",
        ],
        'bureau' => ['Bureau', 'Conférence des présidents'],
        'evenements' => ['Colloque', 'Événement', 'Exposition', 'Soirée', 'Soirée-concert', 'Réunions de travail'],
    ];

    const PARAMETERS = [
        'Actualités' => [
            'news_limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 15,
                'title' => 'Maximum number of items',
            ],
        ],
        'Agenda' => [
            'event_type' => [
                'name' => 'Type',
                'type' => 'list',
                'values' => [
                    'Tout' => 'all',
                    'Séances plénières' => 'pleniere',
                    'Commissions' => 'commissions',
                    'Bureau & Conférence des présidents' => 'bureau',
                    'Colloques & événements' => 'evenements',
                ],
            ],
            'event_limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 30,
                'title' => 'Maximum number of events (last 14 days and upcoming)',
            ],
        ],
        'Documents parlementaires' => [
            'doc_type' => [
                'name' => 'Type',
                'type' => 'list',
                'values' => [
                    'Tous' => 'all',
                    'Questions écrites' => 'questions-ecrites',
                    'Questions orales' => 'questions-orales',
                    "Questions d'actualité" => 'questions-actualite',
                    'Interpellations' => 'interpellations',
                    'Décrets (projets et propositions)' => 'decrets',
                    'Propositions de résolution' => 'resolutions',
                    'Textes adoptés' => 'textes-adoptes',
                    'Comptes rendus intégraux' => 'comptes-rendus',
                    'Rapports' => 'rapports',
                ],
            ],
            'doc_query' => [
                'name' => 'Keyword',
                'exampleValue' => 'enseignement',
                'title' => 'Full-text search (subject, author, minister…)',
            ],
            'doc_limit' => [
                'name' => 'Limit',
                'type' => 'number',
                'defaultValue' => 30,
                'title' => 'Maximum number of items',
            ],
        ],
    ];

    public function collectData()
    {
        switch ($this->queriedContext) {
            case 'Actualités':
                $this->collectNews();
                break;
            case 'Agenda':
                $this->collectAgenda();
                break;
            case 'Documents parlementaires':
                $this->collectDocuments();
                break;
        }
    }

    private function collectNews(): void
    {
        foreach ($this->search('news', '', ['hitsPerPage' => $this->limit('news_limit')]) as $hit) {
            $item = $this->baseItem($hit, $hit['start_date'] ?? null);
            $image = $hit['image']['file']['url'] ?? null;
            $item['categories'] = array_filter([$hit['category'] ?? null]);
            if ($image) {
                $item['enclosures'] = [$image];
                $item['content'] = sprintf('<p><img src="%s" alt="%s"></p>', e($image), e($hit['image_alt'] ?? ''));
            }
            $this->items[] = $item;
        }
    }

    private function collectAgenda(): void
    {
        // Commission meetings are published only days ahead, so "upcoming only" is often empty: take the last
        // 14 days plus everything scheduled, then keep the latest dates. The index returns them oldest first.
        $since = new DateTime('today -14 days', new DateTimeZone('Europe/Brussels'));
        $options = [
            'hitsPerPage' => 100,
            'numericFilters' => ['start_date>=' . $since->getTimestamp()],
        ];
        $group = self::EVENT_GROUPS[$this->getInput('event_type')] ?? null;
        if ($group) {
            $options['facetFilters'] = [array_map(fn ($t) => 'event_type:' . $t, $group)];
        }

        foreach ($this->search('event', '', $options) as $hit) {
            $item = $this->baseItem($hit, $hit['start_date'] ?? null);
            $hours = trim(substr($hit['start_hour'] ?? '', 0, 5) . ' – ' . substr($hit['end_hour'] ?? '', 0, 5), ' –');
            $date = isset($item['timestamp']) ? date('d/m/Y', $item['timestamp']) : '';
            $item['title'] = trim($date . ' ' . substr($hit['start_hour'] ?? '', 0, 5)) . ' – ' . $item['title'];
            $item['categories'] = array_filter([$hit['event_type'] ?? null]);
            $item['content'] = sprintf(
                '<p><strong>%s</strong>%s%s</p>%s',
                e($hit['event_type'] ?? ''),
                $hours ? ' · ' . e($hours) : '',
                !empty($hit['event_type_closed_doors']) ? ' · huis clos' : '',
                $hit['description'] ?? ''
            );
            $this->items[] = $item;
        }
        // Keep the events closest to today: recurring meetings planned months ahead must not crowd out this week's
        $now = time();
        usort($this->items, fn ($a, $b) => abs(($a['timestamp'] ?? 0) - $now) <=> abs(($b['timestamp'] ?? 0) - $now));
        $this->items = array_slice($this->items, 0, $this->limit('event_limit'));
    }

    private function collectDocuments(): void
    {
        $options = ['hitsPerPage' => $this->limit('doc_limit')];
        $group = self::DOCUMENT_GROUPS[$this->getInput('doc_type')] ?? null;
        if ($group) {
            $options['facetFilters'] = [array_map(fn ($t) => 'document_type:' . $t, $group)];
        }

        foreach ($this->search('document', (string) $this->getInput('doc_query'), $options) as $hit) {
            $item = $this->baseItem($hit, $hit['date'] ?? null);
            $subject = trim($hit['description'] ?? '');
            // The index title is a bare id ("Document QA 001867450"); type + subject reads better. The subject
            // can carry extra lines ("Question n°862 du 31 août 2026"): only the first goes in the title.
            $firstLine = trim(strtok($subject, "\n") ?: '');
            $item['title'] = ($hit['document_type'] ?? 'Document') . ($firstLine ? ' – ' . $firstLine : '');

            $authors = $hit['author'] ?? [];
            $groups = $hit['political_group'] ?? [];
            if ($authors) {
                $item['author'] = implode(', ', $authors) . ($groups ? ' (' . implode(', ', $groups) . ')' : '');
            }
            $item['categories'] = array_values(array_filter(array_merge(
                [$hit['document_type'] ?? null],
                $hit['matter'] ?? []
            )));

            $rows = [
                'Type' => $hit['document_type'] ?? null,
                'Auteur' => $item['author'] ?? null,
                'Destinataire' => implode(', ', $hit['recipient'] ?? []),
                'Commission' => implode(', ', $hit['commission'] ?? []),
                'Session' => implode(', ', $hit['session'] ?? []),
                'Matières' => implode(', ', $hit['matter'] ?? []),
            ];
            $content = $subject ? '<p>' . nl2br(e($subject)) . '</p>' : '';
            $content .= '<ul>';
            foreach (array_filter($rows) as $label => $value) {
                $content .= sprintf('<li><strong>%s :</strong> %s</li>', e($label), e($value));
            }
            $item['content'] = $content . '</ul>';
            $this->items[] = $item;
        }
    }

    /**
     * Query one of the site's Algolia indices through its own public caching proxy (what the site's pages call).
     */
    private function search(string $index, string $query, array $options): array
    {
        $json = getContents(self::URI . 'api/algolia-cache-single-index', [], [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'index' => $index,
                'query' => $query,
                'options' => json_encode($options, JSON_UNESCAPED_UNICODE),
            ]),
        ]);
        return Json::decode($json)['hits'] ?? [];
    }

    private function baseItem(array $hit, $timestamp): array
    {
        $uri = urljoin(self::URI, $hit['url'] ?? '');
        $item = [
            'uri' => $uri,
            'uid' => $hit['objectID'] ?? $uri,
            'title' => html_entity_decode((string) ($hit['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
        if ($timestamp) {
            $item['timestamp'] = (int) $timestamp;
        }
        return $item;
    }

    private function limit(string $name): int
    {
        return min(100, max(1, (int) $this->getInput($name)));
    }

    public function getURI()
    {
        switch ($this->queriedContext) {
            case 'Actualités':
                return self::URI . 'actualite/';
            case 'Agenda':
                return self::URI . 'agenda/';
            case 'Documents parlementaires':
                return self::URI . 'documents-parlementaires/';
        }
        return parent::getURI();
    }

    public function getName()
    {
        if (!$this->queriedContext) {
            return parent::getName();
        }
        $key = $this->queriedContext === 'Agenda' ? $this->getKey('event_type')
            : ($this->queriedContext === 'Documents parlementaires' ? $this->getKey('doc_type') : null);
        $suffix = $key && !in_array($key, ['Tout', 'Tous'], true) ? ' – ' . $key : '';
        return 'PFWB – ' . $this->queriedContext . $suffix;
    }
}
