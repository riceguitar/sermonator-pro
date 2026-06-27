<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

use Sermonator\Frontend\Bible\BibleWarmer;

/**
 * Wires the Sermon Details authoring layer: meta registration, REST endpoint, save-time date
 * normalization, REST sanitization, the classic meta-box React UI, and the synchronous
 * warm-on-save cache primer (Phase 3b Task 9).
 */
final class AuthoringServiceProvider {
	private SermonMetaRegistrar $metaRegistrar;
	private AudioMetaController $audioController;
	private BibleParseController $bibleParseController;
	private SermonDateNormalizer $dateNormalizer;
	private SermonRefsCapture $refsCapture;
	private SermonMetaRestSanitizer $metaRestSanitizer;
	private SermonRefsRestSanitizer $refsRestSanitizer;
	private SermonMetaBox $metaBox;
	private BibleWarmer $bibleWarmer;

	public function __construct(
		?SermonMetaRegistrar $metaRegistrar = null,
		?AudioMetaController $audioController = null,
		?SermonDateNormalizer $dateNormalizer = null,
		?SermonMetaRestSanitizer $metaRestSanitizer = null,
		?SermonMetaBox $metaBox = null,
		?SermonRefsCapture $refsCapture = null,
		?BibleWarmer $bibleWarmer = null,
		?BibleParseController $bibleParseController = null,
		?SermonRefsRestSanitizer $refsRestSanitizer = null
	) {
		$this->metaRegistrar        = $metaRegistrar ?? new SermonMetaRegistrar();
		$this->audioController      = $audioController ?? new AudioMetaController();
		$this->bibleParseController = $bibleParseController ?? new BibleParseController();
		$this->dateNormalizer       = $dateNormalizer ?? new SermonDateNormalizer();
		$this->refsCapture          = $refsCapture ?? new SermonRefsCapture();
		$this->metaRestSanitizer    = $metaRestSanitizer ?? new SermonMetaRestSanitizer();
		$this->refsRestSanitizer    = $refsRestSanitizer ?? new SermonRefsRestSanitizer();
		$this->metaBox              = $metaBox ?? new SermonMetaBox();
		$this->bibleWarmer          = $bibleWarmer ?? new BibleWarmer();
	}

	public function hook(): void {
		add_action(
			'init',
			function (): void {
				$this->metaRegistrar->register();
			},
			20
		);

		add_action(
			'rest_api_init',
			function (): void {
				$this->audioController->register();
				$this->bibleParseController->register();
			}
		);

		$this->metaRestSanitizer->hook();
		$this->refsRestSanitizer->hook();
		$this->dateNormalizer->hook();
		$this->refsCapture->hook();
		// Warm-on-save runs AFTER refsCapture (its own hook priorities enforce the order)
		// so the envelope it primes the cache from is already persisted.
		$this->bibleWarmer->hook();
		$this->metaBox->hook();
	}
}
