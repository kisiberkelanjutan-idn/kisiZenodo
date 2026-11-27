<?php

namespace APP\plugins\generic\kisiZenodo\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PKP\context\Context;
use PKP\core\PKPApplication;

class ZenodoMetadataBuilder
{

    public function buildRecordPayload(
        Submission $submission,
        $publication,
        Context $context,
        array $pluginSettings = []
    ): array {
        $request = Application::get()->getRequest();
        $dispatcher = Application::get()->getDispatcher();
        $locale = (string) ($publication->getData('locale') ?: $context->getPrimaryLocale() ?: 'en');

        $rawTitle = (string) ($publication->getLocalizedFullTitle($locale) ?: $publication->getLocalizedFullTitle());
        $cleanTitle = trim(preg_replace('/\s+/u', ' ', strip_tags($rawTitle))) ?: ('Article #' . $submission->getId());

        $rawAbstract = (string) ($publication->getLocalizedData('abstract', $locale) ?: $publication->getLocalizedData('abstract') ?: '');
        $cleanAbstract = trim(strip_tags($rawAbstract, '<p><br><b><strong><i><em><ul><ol><li><sup><sub>'));
        if (strlen( trim(strip_tags($cleanAbstract)) ) < 3) {
            $cleanAbstract = '<p>' . htmlspecialchars($cleanTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $datePublished = substr((string) ($publication->getData('datePublished') ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePublished)) {
            $datePublished = date('Y-m-d');
        }

        $journalTitle = trim((string) $context->getLocalizedName()) ?: 'Kisi Berkelanjutan: Sains Medis dan Kesehatan';
        $issn = trim((string) (
            $pluginSettings['defaultIssn']
            ?? $context->getData('onlineIssn')
            ?? $context->getData('printIssn')
            ?? '3062-9063'
        ));

        $licenseId = $this->resolveSpdxLicenseId(
            (string) ($publication->getData('licenseUrl') ?: $context->getData('licenseUrl') ?: ''),
            (string) ($pluginSettings['defaultLicense'] ?? 'cc-by-sa-4.0')
        );

        $langIso3 = $this->mapLocaleToIso639_3($locale, $publication);
        $creators = $this->buildCreators($publication, $locale);
        $subjects = $this->buildSubjects($publication);

        $articleUrl = $dispatcher->url(
            $request,
            PKPApplication::ROUTE_PAGE,
            $context->getPath(),
            'article',
            'view',
            [$submission->getBestId()]
        );

        $journalFields = [
            'title' => $journalTitle,
        ];
        if ($issn !== '') {
            $journalFields['issn'] = $issn;
        }

        $issueId = (int) ($publication->getData('issueId') ?? 0);
        if ($issueId > 0) {
            try {
                $issue = Repo::issue()->get($issueId);
                if ($issue) {
                    $vol = trim((string) $issue->getData('volume'));
                    $num = trim((string) $issue->getData('number'));
                    if ($vol !== '' && $vol !== '0') {
                        $journalFields['volume'] = $vol;
                    }
                    if ($num !== '' && $num !== '0') {
                        $journalFields['issue'] = $num;
                    }
                }
            } catch (\Throwable $e) {

            }
        }

        $pages = trim((string) ($publication->getData('pages') ?? ''));
        if ($pages !== '') {
            $journalFields['pages'] = $pages;
        }

        $metadata = [
            'resource_type' => ['id' => 'publication-article'],
            'title' => $cleanTitle,
            'publisher' => $journalTitle,
            'publication_date' => $datePublished,
            'description' => $cleanAbstract,
            'creators' => $creators,
            'rights' => [
                ['id' => $licenseId],
            ],
            'languages' => [
                ['id' => $langIso3],
            ],
            'related_identifiers' => [
                [
                    'identifier' => $articleUrl,
                    'scheme' => 'url',
                    'relation_type' => ['id' => 'ispublishedin'],
                    'resource_type' => ['id' => 'publication-article'],
                ],
            ],
        ];

        if (!empty($subjects)) {
            $metadata['subjects'] = $subjects;
        }

        return [
            'access' => [
                'record' => 'public',
                'files' => 'public',
            ],
            'files' => [
                'enabled' => true,
            ],
            'metadata' => $metadata,
            'custom_fields' => [
                'journal:journal' => $journalFields,
            ],
        ];
    }

    private function buildCreators($publication, string $locale): array
    {
        $authors = $publication->getData('authors');
        if (empty($authors)) {
            try {
                $pubId = (int) $publication->getId();
                if ($pubId > 0) {
                    $authors = Repo::author()->getCollector()->filterByPublicationIds([$pubId])->getMany();
                }
            } catch (\Throwable $e) {
                $authors = [];
            }
        }

        $creators = [];
        if (is_iterable($authors)) {
            foreach ($authors as $author) {
                if (!is_object($author)) {
                    continue;
                }

                $given = trim((string) ($author->getLocalizedGivenName($locale) ?: $author->getLocalizedGivenName()));
                $family = trim((string) ($author->getLocalizedFamilyName($locale) ?: $author->getLocalizedFamilyName()));
                if ($given === '' && $family === '') {
                    continue;
                }

                $personOrOrg = [
                    'type' => 'personal',
                ];
                if ($family !== '') {
                    $personOrOrg['family_name'] = $family;
                    if ($given !== '') {
                        $personOrOrg['given_name'] = $given;
                    }
                } else {
                    $personOrOrg['family_name'] = $given;
                }

                $rawOrcid = trim((string) ($author->getOrcid() ?: $author->getData('orcid') ?: ''));
                if ($rawOrcid !== '' && preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', $rawOrcid, $m)) {
                    $personOrOrg['identifiers'] = [
                        [
                            'scheme' => 'orcid',
                            'identifier' => strtoupper($m[1]),
                        ],
                    ];
                }

                $affiliations = $this->extractAuthorAffiliations($author, $locale);
                $creatorEntry = [
                    'person_or_org' => $personOrOrg,
                ];
                if (!empty($affiliations)) {
                    $creatorEntry['affiliations'] = $affiliations;
                }

                $creators[] = $creatorEntry;
            }
        }

        if (empty($creators)) {
            $creators[] = [
                'person_or_org' => [
                    'type' => 'personal',
                    'family_name' => 'Editorial Team',
                ],
            ];
        }

        return $creators;
    }

    private function extractAuthorAffiliations($author, string $locale): array
    {
        $result = [];
        $seen = [];

        $addAff = static function (string $name, ?string $rorRaw = null) use (&$result, &$seen): void {
            $cleanName = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
            $rorCode = null;
            if ($rorRaw !== null && $rorRaw !== '') {
                if (preg_match('~(?:ror\.org/)?(0[a-z0-9]{6}[0-9]{2})\b~i', $rorRaw, $rm)) {
                    $rorCode = strtolower($rm[1]);
                }
            }
            if ($cleanName === '' && $rorCode === null) {
                return;
            }
            $sig = strtolower($cleanName . '|' . ($rorCode ?? ''));
            if (isset($seen[$sig])) {
                return;
            }
            $seen[$sig] = true;

            $item = [];
            if ($rorCode !== null) {
                $item['id'] = $rorCode;
            }
            if ($cleanName !== '') {
                $item['name'] = $cleanName;
            }
            $result[] = $item;
        };

        if (method_exists($author, 'getAffiliations')) {
            try {
                $affObjs = $author->getAffiliations();
                if (is_iterable($affObjs)) {
                    foreach ($affObjs as $affObj) {
                        if (!is_object($affObj)) {
                            continue;
                        }
                        $affName = method_exists($affObj, 'getLocalizedName')
                            ? (string) ($affObj->getLocalizedName($locale) ?: $affObj->getLocalizedName())
                            : '';
                        $rorVal = method_exists($affObj, 'getRor')
                            ? (string) $affObj->getRor()
                            : (method_exists($affObj, 'getData') ? (string) ($affObj->getData('ror') ?? '') : '');
                        $addAff($affName, $rorVal);
                    }
                }
            } catch (\Throwable $e) {

            }
        }

        if (empty($result) && method_exists($author, 'getLocalizedData')) {
            $legacyAff = (string) ($author->getLocalizedData('affiliation', $locale) ?: $author->getLocalizedData('affiliation') ?: '');
            $legacyRor = method_exists($author, 'getData')
                ? (string) ($author->getData('rorId') ?: $author->getData('ror') ?: '')
                : '';
            if ($legacyAff !== '' || $legacyRor !== '') {
                $addAff($legacyAff, $legacyRor);
            }
        }

        return $result;
    }

    private function buildSubjects($publication): array
    {
        $keywordTerms = [];
        $subjectTerms = [];
        $pubId = method_exists($publication, 'getId') ? (int) $publication->getId() : 0;

        if ($pubId > 0) {
            try {
                if (
                    Schema::hasTable('controlled_vocabs')
                    && Schema::hasTable('controlled_vocab_entries')
                    && Schema::hasTable('controlled_vocab_entry_settings')
                ) {
                    $rows = DB::table('controlled_vocabs as cv')
                        ->join('controlled_vocab_entries as cve', 'cve.controlled_vocab_id', '=', 'cv.controlled_vocab_id')
                        ->join('controlled_vocab_entry_settings as cves', 'cves.controlled_vocab_entry_id', '=', 'cve.controlled_vocab_entry_id')
                        ->where('cv.assoc_id', $pubId)
                        ->whereIn('cv.symbolic', ['submissionKeyword', 'submissionSubject'])
                        ->whereNotNull('cves.setting_value')
                        ->where('cves.setting_value', '!=', '')
                        ->select('cv.symbolic', 'cves.setting_value')
                        ->get();

                    foreach ($rows as $row) {
                        $val = (string) ($row->setting_value ?? '');
                        if ($row->symbolic === 'submissionKeyword') {
                            $keywordTerms[] = $val;
                        } elseif ($row->symbolic === 'submissionSubject') {
                            $subjectTerms[] = $val;
                        }
                    }
                }
            } catch (\Throwable $e) {

            }
        }

        if (method_exists($publication, 'getLocalizedData')) {
            $this->flattenStrings($publication->getLocalizedData('keywords'), $keywordTerms);
            $this->flattenStrings($publication->getLocalizedData('subjects'), $subjectTerms);
        }

        $subjects = [];
        $seen = [];

        $appendUnique = static function (string $raw) use (&$subjects, &$seen): void {
            $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
            if ($clean === '' || strlen($clean) > 200) {
                return;
            }
            $key = mb_strtolower($clean, 'UTF-8');
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $subjects[] = ['subject' => $clean];
        };

        foreach ($keywordTerms as $kw) {
            foreach ($this->splitDelimitedTerms($kw) as $part) {
                if (!$this->hasExplicitEditorPrefix($part)) {
                    $appendUnique($part);
                }
            }
        }

        foreach ($subjectTerms as $subj) {
            foreach ($this->splitDelimitedTerms($subj) as $part) {
                if ($this->isSdgSubject($part)) {
                    $appendUnique($part);
                }
            }
        }

        return $subjects;
    }

    private function splitDelimitedTerms(string $raw): array
    {
        $parts = preg_split('/[;|\r\n]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $trimmed = trim($p);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out;
    }

    private function hasExplicitEditorPrefix(string $term): bool
    {
        return (bool) preg_match(
            '/^(?:review\s*editors?|academic\s*editors?|handling\s*editors?|section\s*editors?|editors?|reviewers?)\s*:/iu',
            trim($term)
        );
    }

    private function isSdgSubject(string $term): bool
    {
        $clean = trim($term);
        if ($clean === '' || $this->hasExplicitEditorPrefix($clean)) {
            return false;
        }

        if (preg_match('/\b(?:sdgs?|tpb)\b/iu', $clean)) {
            return true;
        }

        if (preg_match('/\b(?:sustainable\s+development\s+goals?|tujuan\s+pembangunan\s+berkelanjutan)\b/iu', $clean)) {
            return true;
        }

        if (preg_match('/\b(?:goals?|targets?|tujuan)\s*(?:#|:|-)?\s*(?:1[0-7]|0?[1-9])(?:\.\d+[a-z]?)?\b/iu', $clean)) {
            return true;
        }

        $lower = mb_strtolower($clean, 'UTF-8');
        $officialSdgTitles = [
            'no poverty',
            'zero hunger',
            'good health and well-being',
            'good health and wellbeing',
            'quality education',
            'gender equality',
            'clean water and sanitation',
            'affordable and clean energy',
            'decent work and economic growth',
            'industry, innovation and infrastructure',
            'reduced inequalities',
            'sustainable cities and communities',
            'responsible consumption and production',
            'climate action',
            'life below water',
            'life on land',
            'peace, justice and strong institutions',
            'partnerships for the goals',
            'tanpa kemiskinan',
            'tanpa kelaparan',
            'kehidupan sehat dan sejahtera',
            'pendidikan berkualitas',
            'kesetaraan gender',
            'air bersih dan sanitasi layak',
            'energi bersih dan terjangkau',
            'pekerjaan layak dan pertumbuhan ekonomi',
            'industri, inovasi dan infrastruktur',
            'berkurangnya kesenjangan',
            'kota dan permukiman yang berkelanjutan',
            'konsumsi dan produksi yang bertanggung jawab',
            'penanganan perubahan iklim',
            'ekosistem lautan',
            'ekosistem daratan',
            'perdamaian, keadilan dan kelembagaan yang tangguh',
            'kemitraan untuk mencapai tujuan',
        ];

        foreach ($officialSdgTitles as $title) {
            if (str_contains($lower, $title)) {
                return true;
            }
        }

        return false;
    }

    private function flattenStrings($val, array &$out): void
    {
        if (is_string($val)) {
            $out[] = $val;
        } elseif (is_array($val)) {
            foreach ($val as $item) {
                $this->flattenStrings($item, $out);
            }
        }
    }

    private function resolveSpdxLicenseId(string $licenseUrl, string $fallback): string
    {
        $u = strtolower(trim($licenseUrl));
        if (str_contains($u, 'by-nc-nd/4.0')) {
            return 'cc-by-nc-nd-4.0';
        }
        if (str_contains($u, 'by-nc-sa/4.0')) {
            return 'cc-by-nc-sa-4.0';
        }
        if (str_contains($u, 'by-nc/4.0')) {
            return 'cc-by-nc-4.0';
        }
        if (str_contains($u, 'by-nd/4.0')) {
            return 'cc-by-nd-4.0';
        }
        if (str_contains($u, 'by-sa/4.0')) {
            return 'cc-by-sa-4.0';
        }
        if (str_contains($u, 'by/4.0')) {
            return 'cc-by-4.0';
        }
        if (str_contains($u, 'publicdomain/zero')) {
            return 'cc0-1.0';
        }

        $cleanFallback = strtolower(trim($fallback));
        return $cleanFallback !== '' ? $cleanFallback : 'cc-by-sa-4.0';
    }

    private function mapLocaleToIso639_3(string $locale, $publication): string
    {
        $galleys = $publication->getData('galleys') ?? [];
        foreach ($galleys as $galley) {
            $label = strtolower((string) $galley->getGalleyLabel());
            if (str_contains($label, 'indonesia')) {
                return 'ind';
            }
            if (str_contains($label, 'english')) {
                return 'eng';
            }
        }

        $prefix = strtolower(substr(trim($locale), 0, 2));
        return match ($prefix) {
            'id', 'in' => 'ind',
            'en' => 'eng',
            default => 'ind',
        };
    }
}
