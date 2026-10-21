import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/page-header';
import { PastImports } from '@/features/import/past-imports';
import { PreviewPanel } from '@/features/import/preview-panel';
import type {
    ImportBatchRow,
    ImportLimits,
    ImportPreview,
} from '@/features/import/types';
import { UploadPanel } from '@/features/import/upload-panel';
import { RecordsTabs } from '@/features/records/records-tabs';
import type { Paged } from '@/types';

type Props = {
    /** The uploaded file's check, or null when no file is waiting. */
    preview: ImportPreview | null;
    limits: ImportLimits;
    batches: Paged<ImportBatchRow>;
};

/**
 * Patient Records > Import: bring visits written during a downtime into the
 * system (Step 6j). Admin only. Download the template, upload the filled
 * file, check the preview, confirm. Past imports can be undone.
 */
export default function RecordsImport({ preview, limits, batches }: Props) {
    const { url } = usePage();

    return (
        <>
            <Head title="Import visits" />

            <PageHeader
                title="Patient Records"
                description={`Import visits written while the system was down. Visits before ${limits.earliest} are not accepted.`}
            />

            <RecordsTabs current="import" url={url} />

            {/* A new upload replaces the preview: the key resets the form. */}
            <UploadPanel limits={limits} hasPreview={preview !== null} />

            {preview && <PreviewPanel key={preview.token} preview={preview} />}

            <PastImports batches={batches} token={preview?.token ?? null} />
        </>
    );
}
