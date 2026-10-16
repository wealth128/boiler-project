/*
 * The header and the signature block of the report sheet. Used by the
 * Reports screen (and so by Print) and by the live preview on the Hospital
 * settings screen, so the two always look the same.
 */

export type SheetHeading = {
    hospital_name: string;
    office: string;
    title: string;
    period_label: string;
    /** Set when the report is for one category only. */
    category?: string | null;
    /** Address of the logo picture, or none. */
    logo_url?: string | null;
};

export type SheetSignatories = {
    prepared_by: string;
    prepared_by_title: string;
    noted_by: string;
    noted_by_title: string;
    /** Addresses of the signature pictures, or none. */
    prepared_signature_url?: string | null;
    noted_signature_url?: string | null;
    /** Small note under the signatures, e.g. "Not final – month still open". */
    signature_note?: string | null;
};

export function ReportHeader({ sheet }: { sheet: SheetHeading }) {
    return (
        <header className="mb-4 border-b-2 border-foreground pb-3 text-center">
            {sheet.logo_url && (
                // The hospital name is written right below, so the picture is decoration.
                <img
                    src={sheet.logo_url}
                    alt=""
                    className="mx-auto mb-2 max-h-16 max-w-40 object-contain"
                />
            )}
            <div className="text-xs tracking-[0.12em] text-muted-foreground uppercase">
                {sheet.hospital_name} · {sheet.office}
            </div>
            <h2 className="mt-1 mb-0.5 text-lg font-semibold">{sheet.title}</h2>
            <div className="font-mono text-sm">
                {sheet.period_label}
                {sheet.category ? ` · ${sheet.category} only` : ''}
            </div>
        </header>
    );
}

export function ReportSignatures({ sheet }: { sheet: SheetSignatories }) {
    return (
        <div className="mt-9 break-inside-avoid">
            <div className="grid items-end gap-10 sm:grid-cols-2">
                <Signature
                    name={sheet.prepared_by}
                    caption={`Prepared by · ${sheet.prepared_by_title}`}
                    pictureUrl={sheet.prepared_signature_url}
                />
                <Signature
                    name={sheet.noted_by}
                    caption={`Noted by · ${sheet.noted_by_title}`}
                    pictureUrl={sheet.noted_signature_url}
                />
            </div>
            {sheet.signature_note && (
                <p className="mt-2 text-xs text-muted-foreground italic">
                    {sheet.signature_note}
                </p>
            )}
        </div>
    );
}

/** The signature picture, when there is one, sits just above the line. */
function Signature({
    name,
    caption,
    pictureUrl,
}: {
    name: string;
    caption: string;
    pictureUrl?: string | null;
}) {
    return (
        <div>
            {pictureUrl && (
                <img
                    src={pictureUrl}
                    alt=""
                    className="mb-1 max-h-14 max-w-48 object-contain"
                />
            )}
            <div className="border-t border-foreground pt-1.5 text-xs text-muted-foreground">
                <b className="block min-h-5 text-sm text-foreground">{name}</b>
                {caption}
            </div>
        </div>
    );
}
