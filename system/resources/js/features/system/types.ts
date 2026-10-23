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

/** The "Show" filter on the System health page (?status=). */
export type HealthFilter = 'all' | HealthStatus;

/** Every check counted by result, for "2 problems, 1 warning". */
export type HealthCounts = {
    problem: number;
    warning: number;
    ok: number;
    total: number;
};
