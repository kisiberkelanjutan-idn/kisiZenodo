<?php

/**
 * @file plugins/generic/kisiZenodo/classes/ZenodoMetadataBuilder.php
 *
 * Maps OJS 3.5 Submission, Publication, Author (with ORCID & ROR), Issue, and Controlled Vocabularies
 * into Zenodo's exact InvenioRDM v1 `/api/records` schema.
 */

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
    /**
     * Build a complete InvenioRDM v1 record payload for creating or updating a Zenodo draft.
     */
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
                // Keep journal title & ISSN if issue lookup fails
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

                // InvenioRDM requires `family_name` for personal creators (handles single-word Indonesian names safely)
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

    /**
     * Extract OJS 3.5 structured affiliations (including ROR IDs) as well as legacy affiliation strings.
     */
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

        // 1. OJS 3.5 structured Affiliation entities (when present)
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
                // Fall back safely below
            }
        }

        // 2. Legacy / fallback affiliation data on Author
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

    /**
     * Extract keywords, subjects, and SDG tags from OJS 3.5 controlled_vocabs & publication_settings.
     */
    private function buildSubjects($publication): array
    {
        $rawTerms = [];
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
                        ->whereIn('cv.symbolic', ['submissionKeyword', 'submissionSubject', 'submissionDiscipline'])
                        ->whereNotNull('cves.setting_value')
                        ->where('cves.setting_value', '!=', '')
                        ->pluck('cves.setting_value');

                    foreach ($rows as $val) {
                        $rawTerms[] = (string) $val;
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        foreach (['keywords', 'subjects'] as $field) {
            if (method_exists($publication, 'getLocalizedData')) {
                $this->flattenStrings($publication->getLocalizedData($field), $rawTerms);
            }
        }

        $subjects = [];
        $seen = [];
        foreach ($rawTerms as $term) {
            $clean = trim(preg_replace('/\s+/u', ' ', strip_tags($term)));
            if ($clean === '' || strlen($clean) > 200) {
                continue;
            }
            $key = mb_strtolower($clean, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $subjects[] = ['subject' => $clean];
        }

        return $subjects;
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
