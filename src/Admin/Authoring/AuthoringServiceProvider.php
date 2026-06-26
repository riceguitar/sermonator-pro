<?php

declare(strict_types=1);

namespace Sermonator\Admin\Authoring;

/**
 * Wires the Sermon Details authoring layer: meta registration, REST endpoint, save-time date
 * normalization, REST sanitization, and the classic meta-box React UI.
 */
final class AuthoringServiceProvider {
	private SermonMetaRegistrar $metaRegistrar;
	private AudioMetaController $audioController;
	private SermonDateNormalizer $dateNormalizer;
	private SermonRefsCapture $refsCapture;
	private SermonMetaRestSanitizer $metaRestSanitizer;
	private SermonMetaBox $metaBox;

	public function __construct(
		?SermonMetaRegistrar $metaRegistrar = null,
		?AudioMetaController $audioController = null,
		?SermonDateNormalizer $dateNormalizer = null,
		?SermonMetaRestSanitizer $metaRestSanitizer = null,
		?SermonMetaBox $metaBox = null,
		?SermonRefsCapture $refsCapture = null
	) {
		$this->metaRegistrar     = $metaRegistrar ?? new SermonMetaRegistrar();
		$this->audioController   = $audioController ?? new AudioMetaController();
		$this->dateNormalizer    = $dateNormalizer ?? new SermonDateNormalizer();
		$this->refsCapture       = $refsCapture ?? new SermonRefsCapture();
		$this->metaRestSanitizer = $metaRestSanitizer ?? new SermonMetaRestSanitizer();
		$this->metaBox           = $metaBox ?? new SermonMetaBox();
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
			}
		);

		$this->metaRestSanitizer->hook();
		$this->dateNormalizer->hook();
		$this->refsCapture->hook();
		$this->metaBox->hook();
	}
}
