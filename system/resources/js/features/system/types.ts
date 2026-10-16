export type HealthStatus = 'ok' | 'warning' | 'problem';

/** One row of the System health page (HealthChecker). */
export type HealthCheck = {
    key: string;
    label: string;
    status: HealthStatus;
    /** What was found, in plain words. */
    detail: string;
    /** The one line telling Admin what to do. */
    todo: string;
};
