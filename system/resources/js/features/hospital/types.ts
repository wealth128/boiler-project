/*
 * Data the Hospital settings screen receives (HospitalController).
 */

/** The six texts of the form, as stored. */
export type HospitalValues = {
    hospital_name: string;
    report_office: string;
    prepared_by: string;
    prepared_by_title: string;
    noted_by: string;
    noted_by_title: string;
};

/** What an empty box means on the report. */
export type HospitalDefaults = {
    hospital_name: string;
    report_office: string;
    prepared_by_title: string;
    noted_by_title: string;
};

export type LogoLimits = {
    logo_max_kb: number;
    logo_max_pixels: number;
};
