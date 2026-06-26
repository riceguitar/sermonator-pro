/**
 * Sermon Details — Gutenberg document-sidebar panel
 *
 * Hand-authored plain-JS build because the @wordpress/scripts toolchain could not complete
 * in this environment. Uses the WordPress script globals already loaded in the block editor.
 *
 * The panel writes only the existing sermonator_* meta keys in their existing formats so the
 * front end, feed, and migration keep working unchanged.
 */
(function () {
    'use strict';

    var __ = wp.i18n.__;
    var registerPlugin = wp.plugins.registerPlugin;
    var PluginDocumentSettingPanel = wp.editor.PluginDocumentSettingPanel;
    var useSelect = wp.data.useSelect;
    var useEntityProp = wp.coreData.useEntityProp;
    var dateI18n = wp.date.dateI18n;
    var getDate = wp.date.getDate;
    var apiFetch = wp.apiFetch;
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var DatePicker = wp.components.DatePicker;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var Button = wp.components.Button;
    var BaseControl = wp.components.BaseControl;
    var Notice = wp.components.Notice;
    var Spinner = wp.components.Spinner;
    var MediaUpload = wp.mediaUtils.MediaUpload;

    var POST_TYPE = 'sermonator_sermon';
    var META_KEYS = {
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

    function dateStringToTimestamp(dateString) {
        if (!dateString) {
            return 0;
        }
        var d = getDate(dateString);
        return d ? Math.floor(d.getTime() / 1000) : 0;
    }

    function timestampToDateString(timestamp) {
        if (!timestamp) {
            return '';
        }
        return dateI18n('Y-m-d', timestamp * 1000);
    }

    function SermonDetailsPanel() {
        var postType = useSelect(function (select) {
            return select('core/editor').getCurrentPostType();
        }, []);

        var entityProp = useEntityProp('postType', POST_TYPE, 'meta');
        var meta = entityProp[0];
        var setMeta = entityProp[1];

        var fetchingState = useState(false);
        var isFetchingAudio = fetchingState[0];
        var setIsFetchingAudio = fetchingState[1];

        var errorState = useState('');
        var audioError = errorState[0];
        var setAudioError = errorState[1];

        if (postType !== POST_TYPE) {
            return null;
        }

        var editingAllowed = window.sermonatorEditor && window.sermonatorEditor.editingAllowed !== false;

        function updateMeta(key, value) {
            var next = Object.assign({}, meta);
            next[key] = value;
            setMeta(next);
        }

        function setAudioDetails(details) {
            updateMeta(META_KEYS.audioDuration, details.duration || '');
            updateMeta(META_KEYS.audioSize, details.size || 0);
        }

        function fetchAudioDetails() {
            var audioId = parseInt(meta[META_KEYS.audioId] || 0, 10);
            var audioUrl = meta[META_KEYS.audio] || '';

            if (!audioId && !audioUrl) {
                return;
            }

            setIsFetchingAudio(true);
            setAudioError('');

            var params = audioId > 0 ? { attachmentId: audioId } : { url: audioUrl };
            apiFetch({
                path: '/sermonator/v1/audio-metadata?' + new URLSearchParams(params).toString(),
            })
                .then(function (result) {
                    setAudioDetails(result);
                })
                .catch(function (err) {
                    setAudioError((err && err.message) || __('Could not read audio details.', 'sermonator'));
                })
                .finally(function () {
                    setIsFetchingAudio(false);
                });
        }

        function onSelectAudio(media) {
            if (!media || !media.url) {
                return;
            }
            updateMeta(META_KEYS.audio, media.url);
            updateMeta(META_KEYS.audioId, media.id || 0);
            fetchAudioDetails();
        }

        function onClearAudio() {
            updateMeta(META_KEYS.audio, '');
            updateMeta(META_KEYS.audioId, 0);
            updateMeta(META_KEYS.audioDuration, '');
            updateMeta(META_KEYS.audioSize, 0);
        }

        function onExternalAudioBlur() {
            updateMeta(META_KEYS.audioId, 0);
            fetchAudioDetails();
        }

        function onSelectFile(key) {
            return function (media) {
                if (media && media.url) {
                    updateMeta(key, media.url);
                }
            };
        }

        function onClearFile(key) {
            return function () {
                updateMeta(key, '');
            };
        }

        var dateValue = timestampToDateString(meta[META_KEYS.date] || 0);
        var isDateAuto = meta[META_KEYS.dateAuto] === 1 || meta[META_KEYS.dateAuto] === '1';

        if (!editingAllowed) {
            return el(
                PluginDocumentSettingPanel,
                {
                    name: 'sermonator-sermon-details',
                    title: __('Sermon Details', 'sermonator'),
                    className: 'sermonator-sermon-details-panel',
                },
                el(
                    Notice,
                    { status: 'warning', isDismissible: false },
                    __('Migration in progress — sermon editing is paused until it finalizes.', 'sermonator')
                )
            );
        }

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'sermonator-sermon-details',
                title: __('Sermon Details', 'sermonator'),
                className: 'sermonator-sermon-details-panel',
            },
            el(
                BaseControl,
                {
                    id: 'sermonator-date-picker',
                    label: __('Preached date', 'sermonator'),
                    help: isDateAuto
                        ? __('Clear the date to auto-fill from the publish date.', 'sermonator')
                        : __('The date is stored as the start of the chosen day in the site timezone.', 'sermonator'),
                },
                el(DatePicker, {
                    currentDate: dateValue ? getDate(dateValue) : undefined,
                    onChange: function (newDate) {
                        updateMeta(META_KEYS.date, dateStringToTimestamp(newDate));
                        updateMeta(META_KEYS.dateAuto, 0);
                    },
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

            el(TextControl, {
                label: __('Scripture', 'sermonator'),
                value: meta[META_KEYS.passage] || '',
                onChange: function (value) {
                    updateMeta(META_KEYS.passage, value);
                },
                placeholder: __('e.g. John 1:1-14', 'sermonator'),
                disabled: !editingAllowed,
            }),

            el(
                BaseControl,
                {
                    id: 'sermonator-audio',
                    label: __('Audio', 'sermonator'),
                    help: __('Media library is preferred. External URLs may not return a size.', 'sermonator'),
                },
                el(
                    'div',
                    { className: 'sermonator-audio-picker' },
                    el(MediaUpload, {
                        onSelect: onSelectAudio,
                        allowedTypes: ['audio'],
                        value: meta[META_KEYS.audioId] || 0,
                        render: function (obj) {
                            return el(
                                Button,
                                { variant: 'secondary', onClick: obj.open, disabled: !editingAllowed },
                                meta[META_KEYS.audio]
                                    ? __('Change audio file', 'sermonator')
                                    : __('Choose audio file', 'sermonator')
                            );
                        },
                    }),
                    meta[META_KEYS.audio]
                        ? el(
                              Button,
                              { variant: 'link', onClick: onClearAudio, disabled: !editingAllowed },
                              __('Remove audio', 'sermonator')
                          )
                        : null
                ),

                el(TextControl, {
                    label: __('External audio URL', 'sermonator'),
                    value: meta[META_KEYS.audio] || '',
                    onChange: function (value) {
                        updateMeta(META_KEYS.audio, value);
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
                            ? el('span', null, el(Spinner), ' ' + __('Reading…', 'sermonator'))
                            : __('Fetch audio details', 'sermonator')
                    ),
                    audioError
                        ? el(Notice, { status: 'error', isDismissible: false }, audioError)
                        : null,
                    meta[META_KEYS.audioDuration]
                        ? el(
                              'p',
                              { className: 'description' },
                              __('Duration:', 'sermonator') + ' ' + meta[META_KEYS.audioDuration]
                          )
                        : null,
                    meta[META_KEYS.audioSize] > 0
                        ? el(
                              'p',
                              { className: 'description' },
                              __('Size:', 'sermonator') +
                                  ' ' +
                                  meta[META_KEYS.audioSize] +
                                  ' ' +
                                  __('bytes', 'sermonator')
                          )
                        : null
                )
            ),

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
            }),

            el(
                BaseControl,
                { id: 'sermonator-notes', label: __('Notes file', 'sermonator') },
                el(MediaUpload, {
                    onSelect: onSelectFile(META_KEYS.notes),
                    allowedTypes: [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain',
                    ],
                    render: function (obj) {
                        return el(
                            'span',
                            null,
                            el(
                                Button,
                                { variant: 'secondary', onClick: obj.open, disabled: !editingAllowed },
                                meta[META_KEYS.notes]
                                    ? __('Change notes file', 'sermonator')
                                    : __('Choose notes file', 'sermonator')
                            ),
                            meta[META_KEYS.notes]
                                ? el(
                                      Button,
                                      {
                                          variant: 'link',
                                          onClick: onClearFile(META_KEYS.notes),
                                          disabled: !editingAllowed,
                                      },
                                      __('Remove', 'sermonator')
                                  )
                                : null
                        );
                    },
                }),
                meta[META_KEYS.notes] ? el('p', { className: 'description' }, meta[META_KEYS.notes]) : null
            ),

            el(
                BaseControl,
                { id: 'sermonator-bulletin', label: __('Bulletin file', 'sermonator') },
                el(MediaUpload, {
                    onSelect: onSelectFile(META_KEYS.bulletin),
                    allowedTypes: ['application/pdf'],
                    render: function (obj) {
                        return el(
                            'span',
                            null,
                            el(
                                Button,
                                { variant: 'secondary', onClick: obj.open, disabled: !editingAllowed },
                                meta[META_KEYS.bulletin]
                                    ? __('Change bulletin file', 'sermonator')
                                    : __('Choose bulletin file', 'sermonator')
                            ),
                            meta[META_KEYS.bulletin]
                                ? el(
                                      Button,
                                      {
                                          variant: 'link',
                                          onClick: onClearFile(META_KEYS.bulletin),
                                          disabled: !editingAllowed,
                                      },
                                      __('Remove', 'sermonator')
                                  )
                                : null
                        );
                    },
                }),
                meta[META_KEYS.bulletin] ? el('p', { className: 'description' }, meta[META_KEYS.bulletin]) : null
            )
        );
    }

    registerPlugin('sermonator-sermon-details', {
        render: SermonDetailsPanel,
    });
})();
