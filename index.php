<?php

/**
 * @file plugins/generic/kisiZenodo/index.php
 *
 * Wrapper for Kisi Zenodo DOI Integration Plugin (OJS 3.5).
 */

require_once __DIR__ . '/KisiZenodoPlugin.php';

return new \APP\plugins\generic\kisiZenodo\KisiZenodoPlugin();
