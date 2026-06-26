/**
 * Sermon Details — Gutenberg document-sidebar panel
 *
 * A thin native UI over the server-side meta contract. Writes only the existing
 * sermonator_* meta keys in their existing formats so the front end, feed, and
 * migration keep working unchanged.
 */
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { dateI18n, getDate } from '@wordpress/date';
import apiFetch from '@wordpress/api-fetch';
import {
    DatePicker,
    TextControl,
    TextareaControl,
    Button,
    BaseControl,
    Notice,
    Spinner,
} from '@wordpress/components';
import { MediaUpload } from '@wordpress/media-utils';
import { useState } from '@wordpress/element';

const POST_TYPE = 'sermonator_sermon';
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
 */
function timestampToDateString(timestamp) {
    if (!timestamp) {
        return '';
    }
    return dateI18n('Y-m-d', timestamp * 1000);
}

function SermonDetailsPanel() {
    const postType = useSelect((select) => select('core/editor').getCurrentPostType(), []);
    const [meta, setMeta] = useEntityProp('postType', POST_TYPE, 'meta');
    const [isFetchingAudio, setIsFetchingAudio] = useState(false);
    const [audioError, setAudioError] = useState('');

    if (postType !== POST_TYPE) {
        return null;
    }

    const editingAllowed = window.sermonatorEditor?.editingAllowed !== false;

    if (!editingAllowed) {
        return (
            <PluginDocumentSettingPanel
                name="sermonator-sermon-details"
                title={__('Sermon Details', 'sermonator')}
                className="sermonator-sermon-details-panel"
            >
                <Notice status="warning" isDismissible={false}>
                    {__('Migration in progress — sermon editing is paused until it finalizes.', 'sermonator')}
                </Notice>
            </PluginDocumentSettingPanel>
        );
    }

    const updateMeta = (key, value) => {
        setMeta({ ...meta, [key]: value });
    };

    const setAudioDetails = ({ duration = '', size = 0, mime = '' }) => {
        updateMeta(META_KEYS.audioDuration, duration || '');
        updateMeta(META_KEYS.audioSize, size || 0);
    };

    const fetchAudioDetails = async () => {
        const audioId = parseInt(meta[META_KEYS.audioId] || 0, 10);
        const audioUrl = meta[META_KEYS.audio] || '';

        if (!audioId && !audioUrl) {
            return;
        }

        setIsFetchingAudio(true);
        setAudioError('');
        try {
            const params = audioId > 0 ? { attachmentId: audioId } : { url: audioUrl };
            const result = await apiFetch({
                path: `/sermonator/v1/audio-metadata?${new URLSearchParams(params).toString()}`,
            });
            setAudioDetails(result);
        } catch (err) {
            setAudioError(err?.message || __('Could not read audio details.', 'sermonator'));
        } finally {
            setIsFetchingAudio(false);
        }
    };

    const onSelectAudio = (media) => {
        if (!media?.url) {
            return;
        }
        updateMeta(META_KEYS.audio, media.url);
        updateMeta(META_KEYS.audioId, media.id || 0);
        fetchAudioDetails();
    };

    const onClearAudio = () => {
        updateMeta(META_KEYS.audio, '');
        updateMeta(META_KEYS.audioId, 0);
        updateMeta(META_KEYS.audioDuration, '');
        updateMeta(META_KEYS.audioSize, 0);
    };

    const onExternalAudioBlur = () => {
        updateMeta(META_KEYS.audioId, 0);
        fetchAudioDetails();
    };

    const onSelectFile = (key) => (media) => {
        if (media?.url) {
            updateMeta(key, media.url);
        }
    };

    const onClearFile = (key) => () => {
        updateMeta(key, '');
    };

    const dateValue = timestampToDateString(meta[META_KEYS.date] || 0);
    const isDateAuto = meta[META_KEYS.dateAuto] === 1 || meta[META_KEYS.dateAuto] === '1';

    return (
        <PluginDocumentSettingPanel
            name="sermonator-sermon-details"
            title={__('Sermon Details', 'sermonator')}
            className="sermonator-sermon-details-panel"
        >
            <BaseControl
                id="sermonator-date-picker"
                label={__('Preached date', 'sermonator')}
                help={
                    isDateAuto
                        ? __('Clear the date to auto-fill from the publish date.', 'sermonator')
                        : __('The date is stored as the start of the chosen day in the site timezone.', 'sermonator')
                }
            >
                <DatePicker
                    currentDate={dateValue ? getDate(dateValue) : undefined}
                    onChange={(newDate) => {
                        updateMeta(META_KEYS.date, dateStringToTimestamp(newDate));
                        updateMeta(META_KEYS.dateAuto, 0);
                    }}
                />
                {dateValue && (
                    <Button
                        variant="link"
                        onClick={() => {
                            updateMeta(META_KEYS.date, 0);
                            updateMeta(META_KEYS.dateAuto, 1);
                        }}
                    >
                        {__('Clear → auto from publish date', 'sermonator')}
                    </Button>
                )}
            </BaseControl>

            <TextControl
                label={__('Scripture', 'sermonator')}
                value={meta[META_KEYS.passage] || ''}
                onChange={(value) => updateMeta(META_KEYS.passage, value)}
                placeholder={__('e.g. John 1:1-14', 'sermonator')}
                disabled={!editingAllowed}
            />

            <BaseControl
                id="sermonator-audio"
                label={__('Audio', 'sermonator')}
                help={__('Media library is preferred. External URLs may not return a size.', 'sermonator')}
            >
                <div className="sermonator-audio-picker">
                    <MediaUpload
                        onSelect={onSelectAudio}
                        allowedTypes={['audio']}
                        value={meta[META_KEYS.audioId] || 0}
                        render={({ open }) => (
                            <Button variant="secondary" onClick={open} disabled={!editingAllowed}>
                                {meta[META_KEYS.audio]
                                    ? __('Change audio file', 'sermonator')
                                    : __('Choose audio file', 'sermonator')}
                            </Button>
                        )}
                    />
                    {meta[META_KEYS.audio] && (
                        <Button variant="link" onClick={onClearAudio} disabled={!editingAllowed}>
                            {__('Remove audio', 'sermonator')}
                        </Button>
                    )}
                </div>

                <TextControl
                    label={__('External audio URL', 'sermonator')}
                    value={meta[META_KEYS.audio] || ''}
                    onChange={(value) => updateMeta(META_KEYS.audio, value)}
                    onBlur={onExternalAudioBlur}
                    placeholder={__('https://example.com/sermon.mp3', 'sermonator')}
                    disabled={!editingAllowed}
                />

                <div className="sermonator-audio-details">
                    <Button
                        variant="secondary"
                        onClick={fetchAudioDetails}
                        disabled={!editingAllowed || isFetchingAudio || (!meta[META_KEYS.audio] && !meta[META_KEYS.audioId])}
                    >
                        {isFetchingAudio ? (
                            <>
                                <Spinner />
                                {__('Reading…', 'sermonator')}
                            </>
                        ) : (
                            __('Fetch audio details', 'sermonator')
                        )}
                    </Button>
                    {audioError && (
                        <Notice status="error" isDismissible={false}>
                            {audioError}
                        </Notice>
                    )}
                    {meta[META_KEYS.audioDuration] && (
                        <p className="description">
                            {__('Duration:', 'sermonator')} {meta[META_KEYS.audioDuration]}
                        </p>
                    )}
                    {meta[META_KEYS.audioSize] > 0 && (
                        <p className="description">
                            {__('Size:', 'sermonator')} {meta[META_KEYS.audioSize]} {__('bytes', 'sermonator')}
                        </p>
                    )}
                </div>
            </BaseControl>

            <TextControl
                label={__('Video URL', 'sermonator')}
                value={meta[META_KEYS.videoUrl] || ''}
                onChange={(value) => updateMeta(META_KEYS.videoUrl, value)}
                placeholder={__('https://youtube.com/watch?v=…', 'sermonator')}
                disabled={!editingAllowed}
            />

            <TextareaControl
                label={__('Video embed', 'sermonator')}
                value={meta[META_KEYS.videoEmbed] || ''}
                onChange={(value) => updateMeta(META_KEYS.videoEmbed, value)}
                help={__('Embed HTML wins when both URL and embed are present.', 'sermonator')}
                disabled={!editingAllowed}
            />

            <BaseControl id="sermonator-notes" label={__('Notes file', 'sermonator')}>
                <MediaUpload
                    onSelect={onSelectFile(META_KEYS.notes)}
                    allowedTypes={['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain']}
                    render={({ open }) => (
                        <>
                            <Button variant="secondary" onClick={open} disabled={!editingAllowed}>
                                {meta[META_KEYS.notes]
                                    ? __('Change notes file', 'sermonator')
                                    : __('Choose notes file', 'sermonator')}
                            </Button>
                            {meta[META_KEYS.notes] && (
                                <Button variant="link" onClick={onClearFile(META_KEYS.notes)} disabled={!editingAllowed}>
                                    {__('Remove', 'sermonator')}
                                </Button>
                            )}
                        </>
                    )}
                />
                {meta[META_KEYS.notes] && (
                    <p className="description">{meta[META_KEYS.notes]}</p>
                )}
            </BaseControl>

            <BaseControl id="sermonator-bulletin" label={__('Bulletin file', 'sermonator')}>
                <MediaUpload
                    onSelect={onSelectFile(META_KEYS.bulletin)}
                    allowedTypes={['application/pdf']}
                    render={({ open }) => (
                        <>
                            <Button variant="secondary" onClick={open} disabled={!editingAllowed}>
                                {meta[META_KEYS.bulletin]
                                    ? __('Change bulletin file', 'sermonator')
                                    : __('Choose bulletin file', 'sermonator')}
                            </Button>
                            {meta[META_KEYS.bulletin] && (
                                <Button variant="link" onClick={onClearFile(META_KEYS.bulletin)} disabled={!editingAllowed}>
                                    {__('Remove', 'sermonator')}
                                </Button>
                            )}
                        </>
                    )}
                />
                {meta[META_KEYS.bulletin] && (
                    <p className="description">{meta[META_KEYS.bulletin]}</p>
                )}
            </BaseControl>
        </PluginDocumentSettingPanel>
    );
}

registerPlugin('sermonator-sermon-details', {
    render: SermonDetailsPanel,
});
