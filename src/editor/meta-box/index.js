/**
 * Sermon Details — native meta box UI
 *
 * React-powered form rendered inside a WordPress meta box. All changes are
 * serialized to a single hidden input (sermonator_meta_json) and saved by PHP.
 *
 * The @wordpress/* imports are externalized by @wordpress/scripts to the runtime
 * window.wp.* globals AND emitted into index.asset.php as the script dependency list, so
 * WordPress enqueues wp-element/wp-components/wp-media-utils/etc. before this bundle runs
 * (wp-media-utils is NOT auto-loaded — MediaUpload would be undefined without the
 * declared dependency).
 */
import { createElement as el, render, useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	TextControl,
	TextareaControl,
	Button,
	BaseControl,
	CheckboxControl,
	Notice,
	Spinner,
} from '@wordpress/components';
import { MediaUpload } from '@wordpress/media-utils';
import { dateI18n, getDate } from '@wordpress/date';
import apiFetch from '@wordpress/api-fetch';

(function () {
	const config = window.sermonatorMetaBox || {};
	const initialMeta = config.initialData || {};
	const editingAllowed = config.editingAllowed !== false;

	const META_KEYS = {
		date: 'sermonator_date',
		dateAuto: 'sermonator_date_auto',
		passage: 'sermonator_bible_passage',
		audio: 'sermonator_audio',
		audioId: 'sermonator_audio_id',
		audioDuration: '_sermonator_audio_duration',
		audioSize: '_sermonator_audio_size',
		videoUrl: 'sermonator_video_url',
		videoEmbed: 'sermonator_video_embed',
		notes: 'sermonator_notes',
		bulletin: 'sermonator_bulletin',
		refs: 'sermonator_bible_refs',
	};

	/**
	 * Stable structural identity for a parsed ref, used to remember the author's
	 * keep/remove decisions across re-parses of the passage (mirrors the server-side
	 * per-ref clearStaleAutoParseEnvelope preservation).
	 *
	 * @param {Object} ref
	 * @return {string}
	 */
	function refKey(ref) {
		return [
			ref.bookUSFM || '',
			ref.chapterStart !== null ? ref.chapterStart : '',
			ref.verseStart !== null ? ref.verseStart : '',
			ref.verseEnd !== null ? ref.verseEnd : '',
			ref.chapterEnd !== null ? ref.chapterEnd : '',
		].join('|');
	}

	/**
	 * Best-effort human label for a ref when the parser did not echo the raw text.
	 *
	 * @param {Object} ref
	 * @return {string}
	 */
	function formatRef(ref) {
		if (ref.raw) {
			return ref.raw;
		}
		let label = (ref.bookUSFM || '') + ' ' + (ref.chapterStart || '');
		if (ref.verseStart !== null) {
			label += ':' + ref.verseStart;
			if (ref.verseEnd !== null && ref.verseEnd !== ref.verseStart) {
				label += '-' + ref.verseEnd;
			}
		}
		return label.trim();
	}

	/**
	 * PRE-FILTER eligibility preview for one parsed ref. Honest by construction: this is
	 * only the pure structural pre-filter the server exposes (L1/L3) — the final inline
	 * decision additionally depends on the source→target versification relation resolved
	 * off the render path and on site settings. Returns a status + an author-readable,
	 * never-color-only label.
	 *
	 * @param {Object} ref
	 * @return {{status: string, inline: boolean, label: string}}
	 */
	function eligibilityFor(ref) {
		const v = ref.validation || {};

		if (!v.inCanon || !v.structurallyValid) {
			return {
				status: 'invalid',
				inline: false,
				label: __('Not a recognized reference — will not be saved', 'sermonator'),
			};
		}

		if (v.inlineEligible) {
			return {
				status: 'inline',
				inline: true,
				label: __('Will show inline', 'sermonator'),
			};
		}

		if (ref.verseStart === null) {
			return {
				status: 'link',
				inline: false,
				label: __('Shown as a link (whole-chapter reference)', 'sermonator'),
			};
		}

		if (ref.chapterEnd !== null) {
			return {
				status: 'link',
				inline: false,
				label: __('Shown as a link (spans multiple chapters)', 'sermonator'),
			};
		}

		return {
			status: 'link',
			inline: false,
			label: __(
				'Shown as a link because verse numbering differs between translations',
				'sermonator'
			),
		};
	}

	/**
	 * Assemble the META_BIBLE_REFS write value from the author's kept chips. Only the
	 * whitelisted STRUCTURAL fields are sent; the server (SermonRefsRestSanitizer) is the
	 * sole authority for provenance (source/confidence/srcVersification*) and re-stamps
	 * exact/authoring/authored on save — client values are never trusted. Returns '' when
	 * nothing is kept (reads server-side as "no envelope", a safe fall-open to the link).
	 *
	 * @param {Object[]} chips
	 * @return {string}
	 */
	function buildRefsEnvelope(chips) {
		const refs = chips
			.filter(function (chip) {
				return chip.kept && !chip.invalid && chip.eligibility.status !== 'invalid';
			})
			.map(function (chip) {
				const ref = chip.ref;
				return {
					bookUSFM: ref.bookUSFM || '',
					chapterStart: ref.chapterStart || 0,
					verseStart: ref.verseStart !== null ? ref.verseStart : null,
					verseEnd: ref.verseEnd !== null ? ref.verseEnd : null,
					chapterEnd: ref.chapterEnd !== null ? ref.chapterEnd : null,
					raw: ref.raw || formatRef(ref),
				};
			});

		if (!refs.length) {
			return '';
		}

		return JSON.stringify({ refs: refs });
	}

	/**
	 * Fetch the live parse of a free-text passage from the read-only preview endpoint.
	 *
	 * @param {string} passage
	 * @return {Promise<Object[]>} candidate refs (each carrying a `validation` block)
	 */
	function fetchParse(passage) {
		return apiFetch({
			path:
				'/sermonator/v1/bible-parse?' +
				new URLSearchParams({ passage: passage }).toString(),
		}).then(function (result) {
			return result && Array.isArray(result.refs) ? result.refs : [];
		});
	}

	/**
	 * Convert a date-only picker value (YYYY-MM-DD) into a Unix timestamp for the start
	 * of that day in the site timezone.
	 *
	 * @param {string} dateString
	 * @return {number}
	 */
	function dateStringToTimestamp(dateString) {
		if (!dateString) {
			return 0;
		}
		const d = getDate(dateString);
		return d ? Math.floor(d.getTime() / 1000) : 0;
	}

	/**
	 * Convert a stored Unix timestamp into a YYYY-MM-DD string in the site timezone.
	 *
	 * @param {number} timestamp
	 * @return {string}
	 */
	function timestampToDateString(timestamp) {
		if (!timestamp) {
			return '';
		}
		return dateI18n('Y-m-d', timestamp * 1000);
	}

	/**
	 * Extract a display filename from a URL string.
	 *
	 * @param {string} url
	 * @return {string}
	 */
	function getFileName(url) {
		if (!url) {
			return '';
		}
		try {
			const u = new URL(url);
			const parts = u.pathname.split('/');
			return parts[parts.length - 1] || url;
		} catch (e) {
			const parts = url.split('/');
			return parts[parts.length - 1] || url;
		}
	}

	/**
	 * Convert a byte count into a human-readable string.
	 *
	 * @param {number|string} bytes
	 * @return {string}
	 */
	function formatBytes(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (!bytes) {
			return '';
		}
		const units = ['B', 'KB', 'MB', 'GB'];
		let size = bytes;
		let i = 0;
		while (size >= 1024 && i < units.length - 1) {
			size /= 1024;
			i++;
		}
		return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
	}

	/**
	 * Duration / size badges for the current audio file.
	 *
	 * @param {Object} props
	 * @param {string} props.duration
	 * @param {number} props.size
	 */
	function AudioInfo(props) {
		const duration = props.duration;
		const size = parseInt(props.size, 10) || 0;

		if (!duration && !size) {
			return null;
		}

		return el(
			'div',
			{ className: 'sermonator-audio-info' },
			duration
				? el(
						'span',
						{ className: 'sermonator-audio-info__badge' },
						__('Duration', 'sermonator') + ': ' + duration
				  )
				: null,
			size > 0
				? el(
						'span',
						{ className: 'sermonator-audio-info__badge' },
						__('Size', 'sermonator') + ': ' + formatBytes(size)
				  )
				: null
		);
	}

	/**
	 * Single downloadable-file row: name + choose/change/remove actions.
	 *
	 * @param {Object}   props
	 * @param {string}   props.value
	 * @param {string[]} props.allowedTypes
	 * @param {Function} props.onSelect
	 * @param {Function} props.onClear
	 */
	function FileRow(props) {
		const hasFile = !!props.value;

		return el(
			'div',
			{ className: 'sermonator-file-row' },
			el(
				'span',
				{ className: 'sermonator-file-row__name' },
				hasFile
					? getFileName(props.value)
					: el(
							'span',
							{ className: 'sermonator-meta-empty' },
							__('No file chosen', 'sermonator')
					  )
			),
			el(
				'div',
				{ className: 'sermonator-file-row__actions' },
				el(MediaUpload, {
					onSelect: props.onSelect,
					allowedTypes: props.allowedTypes,
					render: function (renderProps) {
						return el(
							Button,
							{
								variant: 'secondary',
								onClick: renderProps.open,
								disabled: !editingAllowed,
							},
							hasFile
								? __('Change file', 'sermonator')
								: __('Choose file', 'sermonator')
						);
					},
				}),
				hasFile
					? el(
							Button,
							{
								variant: 'link',
								onClick: props.onClear,
								disabled: !editingAllowed,
							},
							__('Remove', 'sermonator')
					  )
					: null
			)
		);
	}

	/**
	 * Confirm-chip Scripture authoring panel.
	 *
	 * Fetches the live parse of the current Scripture passage and renders each candidate
	 * reference as an editable confirm CHIP, flagged with its pure pre-filter eligibility
	 * label ("will show inline" vs "shown as a link …"). The author can keep, edit, or
	 * remove chips; the kept chips feed META_BIBLE_REFS, which the server stamps
	 * exact/authoring/authored on save. The panel never writes META_BIBLE_REFS until the
	 * author actually interacts with a chip, so simply opening a sermon never clobbers a
	 * previously curated envelope. props.onRefsChange is called with the envelope JSON
	 * string (or '' when nothing is kept).
	 *
	 * @param {Object}   props
	 * @param {string}   props.passage
	 * @param {boolean}  props.editingAllowed
	 * @param {Function} props.onRefsChange
	 */
	function ScriptureReferences(props) {
		const passage = props.passage || '';
		const canEdit = props.editingAllowed;
		const onRefsChange = props.onRefsChange;

		const [chips, setChips] = useState([]);
		const [isLoading, setIsLoading] = useState(false);
		const [error, setError] = useState('');

		// Has the author interacted with a chip? Until then the panel is read-only
		// preview and writes nothing, so opening a sermon cannot overwrite saved refs.
		const touchedRef = useRef(false);
		// refKey -> true for chips the author removed; preserved across re-parses.
		const dismissedRef = useRef({});
		// The passage string the current chips were built from (avoids redundant fetches).
		const builtFromRef = useRef(null);

		const buildChips = function (refs) {
			return refs.map(function (ref, index) {
				const key = refKey(ref);
				return {
					id: key + '#' + index,
					key: key,
					ref: ref,
					raw: ref.raw || formatRef(ref),
					eligibility: eligibilityFor(ref),
					kept: !dismissedRef.current[key],
					editing: false,
					editText: ref.raw || formatRef(ref),
					invalid: false,
				};
			});
		};

		// Re-parse whenever the passage changes (debounced). The render-time fetch is the
		// read-only preview endpoint; it writes nothing.
		useEffect(
			function () {
				if (builtFromRef.current === passage) {
					return;
				}

				if (!passage.trim()) {
					builtFromRef.current = passage;
					setError('');
					setIsLoading(false);
					setChips([]);
					return;
				}

				let cancelled = false;
				setIsLoading(true);
				setError('');

				const timeoutId = setTimeout(function () {
					fetchParse(passage)
						.then(function (refs) {
							if (cancelled) {
								return;
							}
							builtFromRef.current = passage;
							setChips(buildChips(refs));
						})
						.catch(function (err) {
							if (cancelled) {
								return;
							}
							setError(
								err && err.message
									? err.message
									: __('Could not read Scripture references.', 'sermonator')
							);
							setChips([]);
						})
						.finally(function () {
							if (!cancelled) {
								setIsLoading(false);
							}
						});
				}, 400);

				return function () {
					cancelled = true;
					clearTimeout(timeoutId);
				};
			},
			[passage]
		);

		// Push the envelope to the parent meta ONLY after the author has touched a chip.
		useEffect(
			function () {
				if (!touchedRef.current) {
					return;
				}
				onRefsChange(buildRefsEnvelope(chips));
			},
			// onRefsChange is a stable updateMeta wrapper; fire only on chip changes.
			// eslint-disable-next-line react-hooks/exhaustive-deps
			[chips]
		);

		const updateChip = function (id, changes) {
			touchedRef.current = true;
			setChips(function (prev) {
				return prev.map(function (chip) {
					return chip.id === id ? Object.assign({}, chip, changes) : chip;
				});
			});
		};

		const toggleKeep = function (chip) {
			return function (kept) {
				dismissedRef.current[chip.key] = !kept;
				updateChip(chip.id, { kept: kept });
			};
		};

		const startEdit = function (chip) {
			return function () {
				updateChip(chip.id, { editing: true, editText: chip.raw });
			};
		};

		const cancelEdit = function (chip) {
			return function () {
				updateChip(chip.id, { editing: false, editText: chip.raw, invalid: false });
			};
		};

		const changeEditText = function (chip) {
			return function (value) {
				updateChip(chip.id, { editText: value });
			};
		};

		const confirmEdit = function (chip) {
			return function () {
				const text = (chip.editText || '').trim();
				if (!text) {
					updateChip(chip.id, { invalid: true });
					return;
				}
				fetchParse(text)
					.then(function (refs) {
						const ref = refs[0];
						if (!ref) {
							updateChip(chip.id, { invalid: true });
							return;
						}
						updateChip(chip.id, {
							ref: ref,
							raw: ref.raw || formatRef(ref),
							editText: ref.raw || formatRef(ref),
							eligibility: eligibilityFor(ref),
							editing: false,
							invalid: false,
							key: refKey(ref),
						});
					})
					.catch(function () {
						updateChip(chip.id, { invalid: true });
					});
			};
		};

		const renderChip = function (chip) {
			const classes = ['sermonator-scripture-chip'];
			if (!chip.kept) {
				classes.push('is-removed');
			}
			if (chip.invalid) {
				classes.push('is-invalid');
			}

			if (chip.editing) {
				return el(
					'li',
					{ className: classes.join(' '), key: chip.id },
					el(
						'div',
						{ className: 'sermonator-scripture-chip__edit' },
						el(TextControl, {
							label: __('Edit reference', 'sermonator'),
							value: chip.editText,
							onChange: changeEditText(chip),
							disabled: !canEdit,
						}),
						chip.invalid
							? el(
									'span',
									{ className: 'sermonator-scripture-chip__status', 'data-status': 'invalid' },
									__('Not a recognized reference', 'sermonator')
							  )
							: null,
						el(
							'div',
							{ className: 'sermonator-scripture-chip__actions' },
							el(
								Button,
								{ variant: 'secondary', onClick: confirmEdit(chip), disabled: !canEdit },
								__('Apply', 'sermonator')
							),
							el(
								Button,
								{ variant: 'link', onClick: cancelEdit(chip) },
								__('Cancel', 'sermonator')
							)
						)
					)
				);
			}

			return el(
				'li',
				{ className: classes.join(' '), key: chip.id },
				el(
					'div',
					{ className: 'sermonator-scripture-chip__main' },
					el(CheckboxControl, {
						label: chip.raw,
						checked: chip.kept,
						onChange: toggleKeep(chip),
						disabled: !canEdit || chip.eligibility.status === 'invalid',
						__nextHasNoMarginBottom: true,
					})
				),
				el(
					'span',
					{
						className: 'sermonator-scripture-chip__status',
						'data-status': chip.eligibility.status,
					},
					chip.eligibility.label
				),
				el(
					Button,
					{
						variant: 'link',
						onClick: startEdit(chip),
						disabled: !canEdit,
						className: 'sermonator-scripture-chip__edit-toggle',
					},
					__('Edit', 'sermonator')
				)
			);
		};

		return el(
			'div',
			{ className: 'sermonator-scripture-refs' },
			el(
				'p',
				{ className: 'sermonator-scripture-refs__intro' },
				__(
					'Confirm which references are saved with this sermon. Inline display also depends on site settings and translation.',
					'sermonator'
				)
			),
			isLoading
				? el(
						'span',
						{ className: 'sermonator-fetching' },
						el(Spinner),
						__('Reading references…', 'sermonator')
				  )
				: null,
			error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
			!isLoading && !error && !chips.length && passage.trim()
				? el(
						'p',
						{ className: 'sermonator-meta-empty' },
						__('No recognizable references in this passage.', 'sermonator')
				  )
				: null,
			!passage.trim()
				? el(
						'p',
						{ className: 'sermonator-meta-empty' },
						__('Enter a Scripture passage above to see references.', 'sermonator')
				  )
				: null,
			chips.length
				? el(
						'ul',
						{ className: 'sermonator-scripture-chip-list' },
						chips.map(renderChip)
				  )
				: null
		);
	}

	function SermonMetaBox() {
		const [meta, setMeta] = useState(initialMeta);
		const [isFetchingAudio, setIsFetchingAudio] = useState(false);
		const [audioError, setAudioError] = useState('');
		const [saveStatus, setSaveStatus] = useState('');
		const isMounted = useRef(false);
		const externalAudioUrlRef = useRef(meta[META_KEYS.audio] || '');

		useEffect(
			function () {
				const hidden = document.getElementById('sermonator_meta_json');
				if (hidden) {
					hidden.value = JSON.stringify(meta);
				}
			},
			[meta]
		);

		useEffect(
			function () {
				if (!isMounted.current) {
					isMounted.current = true;
					return;
				}
				if (!editingAllowed || !config.postId) {
					return;
				}

				setSaveStatus(__('Saving…', 'sermonator'));
				const timeoutId = setTimeout(function () {
					apiFetch({
						path: '/wp/v2/sermonator_sermon/' + config.postId,
						method: 'POST',
						data: { meta: meta },
					})
						.then(function () {
							setSaveStatus(__('Saved', 'sermonator'));
							setTimeout(function () {
								setSaveStatus('');
							}, 2000);
						})
						.catch(function (err) {
							setSaveStatus(__('Save failed', 'sermonator'));
							// eslint-disable-next-line no-console
							console.error('Sermonator meta save failed:', err);
						});
				}, 1000);

				return function () {
					clearTimeout(timeoutId);
				};
			},
			[meta]
		);

		const updateMeta = function (key, value) {
			setMeta(function (prevMeta) {
				return Object.assign({}, prevMeta, { [key]: value });
			});
		};

		const setAudioDetails = function (details) {
			details = details || {};
			updateMeta(META_KEYS.audioDuration, details.duration || '');
			updateMeta(META_KEYS.audioSize, details.size || 0);
		};

		const fetchAudioDetails = async function (explicitId, explicitUrl) {
			const audioId = parseInt(explicitId || meta[META_KEYS.audioId] || 0, 10);
			const audioUrl = explicitUrl || meta[META_KEYS.audio] || '';

			if (!audioId && !audioUrl) {
				return;
			}

			setIsFetchingAudio(true);
			setAudioError('');
			try {
				const params = audioId > 0 ? { attachmentId: audioId } : { url: audioUrl };
				const result = await apiFetch({
					path: '/sermonator/v1/audio-metadata?' + new URLSearchParams(params).toString(),
				});
				setAudioDetails(result);
			} catch (err) {
				setAudioError(
					err && err.message
						? err.message
						: __('Could not read audio details.', 'sermonator')
				);
			} finally {
				setIsFetchingAudio(false);
			}
		};

		const onSelectAudio = function (media) {
			if (!media || !media.url) {
				return;
			}
			const audioId = media.id || 0;
			updateMeta(META_KEYS.audio, media.url);
			updateMeta(META_KEYS.audioId, audioId);
			fetchAudioDetails(audioId, media.url);
		};

		const onClearAudio = function () {
			updateMeta(META_KEYS.audio, '');
			updateMeta(META_KEYS.audioId, 0);
			updateMeta(META_KEYS.audioDuration, '');
			updateMeta(META_KEYS.audioSize, 0);
		};

		const onExternalAudioBlur = function () {
			const audioUrl = externalAudioUrlRef.current || '';
			updateMeta(META_KEYS.audioId, 0);
			if (audioUrl) {
				fetchAudioDetails(0, audioUrl);
			}
		};

		const onSelectFile = function (key) {
			return function (media) {
				if (media && media.url) {
					updateMeta(key, media.url);
				}
			};
		};

		const onClearFile = function (key) {
			return function () {
				updateMeta(key, '');
			};
		};

		const dateValue = timestampToDateString(meta[META_KEYS.date] || 0);
		const isDateAuto = meta[META_KEYS.dateAuto] === 1 || meta[META_KEYS.dateAuto] === '1';

		if (!editingAllowed) {
			return el(
				'div',
				{ className: 'sermonator-meta-box' },
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					__('Migration in progress — sermon editing is paused until it finalizes.', 'sermonator')
				)
			);
		}

		const dateHelp = isDateAuto
			? __('Clear the date to auto-fill from the publish date.', 'sermonator')
			: __('The date is stored as the start of the chosen day in the site timezone.', 'sermonator');

		return el(
			'div',
			{ className: 'sermonator-meta-box' },
			saveStatus
				? el('span', { className: 'sermonator-meta-save-status' }, saveStatus)
				: null,
			el(
				'div',
				{ className: 'sermonator-meta-section' },
				el('h3', { className: 'sermonator-meta-section__title' }, __('Sermon Info', 'sermonator')),
				el(
					'div',
					{ className: 'sermonator-meta-grid' },
					el(
						'div',
						{ className: 'sermonator-meta-grid__item' },
						el(TextControl, {
							label: __('Preached date', 'sermonator'),
							help: dateHelp,
							type: 'date',
							value: dateValue,
							onChange: function (value) {
								updateMeta(META_KEYS.date, dateStringToTimestamp(value));
								updateMeta(META_KEYS.dateAuto, 0);
							},
							disabled: !editingAllowed,
						}),
						dateValue
							? el(
									Button,
									{
										variant: 'link',
										onClick: function () {
											updateMeta(META_KEYS.date, 0);
											updateMeta(META_KEYS.dateAuto, 1);
										},
									},
									__('Clear → auto from publish date', 'sermonator')
							  )
							: null
					),
					el(
						'div',
						{ className: 'sermonator-meta-grid__item' },
						el(TextControl, {
							label: __('Scripture', 'sermonator'),
							value: meta[META_KEYS.passage] || '',
							onChange: function (value) {
								updateMeta(META_KEYS.passage, value);
							},
							placeholder: __('e.g. John 1:1-14', 'sermonator'),
							disabled: !editingAllowed,
						}),
						el(ScriptureReferences, {
							passage: meta[META_KEYS.passage] || '',
							editingAllowed: editingAllowed,
							onRefsChange: function (envelope) {
								updateMeta(META_KEYS.refs, envelope);
							},
						})
					)
				)
			),
			el(
				'div',
				{ className: 'sermonator-meta-section' },
				el('h3', { className: 'sermonator-meta-section__title' }, __('Audio', 'sermonator')),
				el(
					BaseControl,
					{
						id: 'sermonator-audio',
						help: __('Media library is preferred. External URLs may not return a size.', 'sermonator'),
					},
					el(
						'div',
						{ className: 'sermonator-audio-row' },
						el(
							'span',
							{ className: 'sermonator-audio-row__name' },
							meta[META_KEYS.audio]
								? getFileName(meta[META_KEYS.audio])
								: el(
										'span',
										{ className: 'sermonator-meta-empty' },
										__('No audio file', 'sermonator')
								  )
						),
						el(AudioInfo, {
							duration: meta[META_KEYS.audioDuration],
							size: meta[META_KEYS.audioSize],
						}),
						el(
							'div',
							{ className: 'sermonator-audio-row__actions' },
							el(MediaUpload, {
								onSelect: onSelectAudio,
								allowedTypes: ['audio'],
								value: meta[META_KEYS.audioId] || 0,
								render: function (renderProps) {
									return el(
										Button,
										{
											variant: 'secondary',
											onClick: renderProps.open,
											disabled: !editingAllowed,
										},
										meta[META_KEYS.audio]
											? __('Replace', 'sermonator')
											: __('Choose audio file', 'sermonator')
									);
								},
							}),
							meta[META_KEYS.audio]
								? el(
										Button,
										{
											variant: 'link',
											onClick: onClearAudio,
											disabled: !editingAllowed,
										},
										__('Remove', 'sermonator')
								  )
								: null
						)
					),
					el(TextControl, {
						label: __('External audio URL', 'sermonator'),
						value: meta[META_KEYS.audio] || '',
						onChange: function (value) {
							externalAudioUrlRef.current = value;
							updateMeta(META_KEYS.audio, value);
							if (!value) {
								updateMeta(META_KEYS.audioDuration, '');
								updateMeta(META_KEYS.audioSize, 0);
							}
						},
						onBlur: onExternalAudioBlur,
						placeholder: __('https://example.com/sermon.mp3', 'sermonator'),
						disabled: !editingAllowed,
					}),
					el(
						'div',
						{ className: 'sermonator-audio-details' },
						el(
							Button,
							{
								variant: 'secondary',
								onClick: fetchAudioDetails,
								disabled:
									!editingAllowed ||
									isFetchingAudio ||
									(!meta[META_KEYS.audio] && !meta[META_KEYS.audioId]),
							},
							isFetchingAudio
								? el(
										'span',
										{ className: 'sermonator-fetching' },
										el(Spinner),
										__('Reading…', 'sermonator')
								  )
								: __('Fetch audio details', 'sermonator')
						),
						el(AudioInfo, {
							duration: meta[META_KEYS.audioDuration],
							size: meta[META_KEYS.audioSize],
						}),
						audioError
							? el(Notice, { status: 'error', isDismissible: false }, audioError)
							: null
					)
				)
			),
			el(
				'div',
				{ className: 'sermonator-meta-section' },
				el('h3', { className: 'sermonator-meta-section__title' }, __('Video', 'sermonator')),
				el(TextControl, {
					label: __('Video URL', 'sermonator'),
					value: meta[META_KEYS.videoUrl] || '',
					onChange: function (value) {
						updateMeta(META_KEYS.videoUrl, value);
					},
					placeholder: __('https://youtube.com/watch?v=…', 'sermonator'),
					disabled: !editingAllowed,
				}),
				el(TextareaControl, {
					label: __('Video embed', 'sermonator'),
					value: meta[META_KEYS.videoEmbed] || '',
					onChange: function (value) {
						updateMeta(META_KEYS.videoEmbed, value);
					},
					help: __('Embed HTML wins when both URL and embed are present.', 'sermonator'),
					disabled: !editingAllowed,
				})
			),
			el(
				'div',
				{ className: 'sermonator-meta-section' },
				el('h3', { className: 'sermonator-meta-section__title' }, __('Downloads', 'sermonator')),
				el(
					'div',
					{ className: 'sermonator-meta-grid' },
					el(
						'div',
						{ className: 'sermonator-meta-grid__item' },
						el(
							BaseControl,
							{ id: 'sermonator-notes', label: __('Notes file', 'sermonator') },
							el(FileRow, {
								value: meta[META_KEYS.notes] || '',
								allowedTypes: [
									'application/pdf',
									'application/msword',
									'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
									'text/plain',
								],
								onSelect: onSelectFile(META_KEYS.notes),
								onClear: onClearFile(META_KEYS.notes),
							})
						)
					),
					el(
						'div',
						{ className: 'sermonator-meta-grid__item' },
						el(
							BaseControl,
							{ id: 'sermonator-bulletin', label: __('Bulletin file', 'sermonator') },
							el(FileRow, {
								value: meta[META_KEYS.bulletin] || '',
								allowedTypes: ['application/pdf'],
								onSelect: onSelectFile(META_KEYS.bulletin),
								onClear: onClearFile(META_KEYS.bulletin),
							})
						)
					)
				)
			)
		);
	}

	const root = document.getElementById('sermonator-meta-box-root');
	if (root) {
		render(el(SermonMetaBox), root);
	}
})();
