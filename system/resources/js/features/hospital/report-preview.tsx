import {
    ReportHeader,
    ReportSignatures,
} from "@/components/shared/report-sheet-parts";
import { ScaledPage } from "@/features/hospital/scaled-page";
import type {
    HospitalDefaults,
    HospitalValues,
} from "@/features/hospital/types";

type ReportPreviewProps = {
    values: HospitalValues;
    defaults: HospitalDefaults;
    /** The logo to show: the picked file or the saved one. */
    logoUrl: string | null;
    /** The signature pictures to show, picked or saved. */
    preparedSignatureUrl: string | null;
    notedSignatureUrl: string | null;
};

/** An empty box means the default, as on the real report. */
function textOr(value: string, fallback: string): string {
    return value.trim() !== "" ? value.trim() : fallback;
}

/**
 * How the report header and the signature block will look on Reports, PDF
 * and Print, with the values being typed: an A4 page, centred and scaled to
 * the width of its panel. It uses the same components as the report itself.
 * The title and period are a sample.
 */
export function ReportPreview({
    values,
    defaults,
    logoUrl,
    preparedSignatureUrl,
    notedSignatureUrl,
}: ReportPreviewProps) {
    const sample = new Intl.DateTimeFormat("en-PH", {
        month: "long",
        year: "numeric",
    }).format(new Date());

    return (
        <ScaledPage label="Preview of the report header and signatures">
            <ReportHeader
                sheet={{
                    hospital_name: textOr(
                        values.hospital_name,
                        defaults.hospital_name,
                    ),
                    office: textOr(
                        values.report_office,
                        defaults.report_office,
                    ),
                    title: "Monthly Patient Census Report",
                    period_label: sample,
                    logo_url: logoUrl,
                }}
            />

            <p className="text-sm text-muted-foreground">
                The counts of the report go here.
            </p>

            <ReportSignatures
                fixedColumns
                sheet={{
                    prepared_by: values.prepared_by.trim(),
                    prepared_by_title: textOr(
                        values.prepared_by_title,
                        defaults.prepared_by_title,
                    ),
                    noted_by: values.noted_by.trim(),
                    noted_by_title: textOr(
                        values.noted_by_title,
                        defaults.noted_by_title,
                    ),
                    prepared_signature_url: preparedSignatureUrl,
                    noted_signature_url: notedSignatureUrl,
                }}
            />
        </ScaledPage>
    );
}
