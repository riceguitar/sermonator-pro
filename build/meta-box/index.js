/**
 * Sermon Details — native meta box UI
 *
 * React-powered form rendered inside a WordPress meta box. All changes are
 * serialized to a single hidden input (sermonator_meta_json) and saved by PHP.
 */
(function () {
	const { createElement: el, render, useState, useEffect, useRef } = wp.element;
	const { __ } = wp.i18n;
	const {
		TextControl,
		TextareaControl,
		Button,
		BaseControl,
		Notice,
		Spinner,
	} = wp.components;
	const { MediaUpload } = wp.mediaUtils;
	const { dateI18n, getDate } = wp.date;
	const apiFetch = wp.apiFetch;

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
	};

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
