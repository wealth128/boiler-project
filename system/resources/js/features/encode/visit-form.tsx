import { Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import EncodeController from '@/actions/App/Http/Controllers/Encode/EncodeController';
import VisitController from '@/actions/App/Http/Controllers/Encode/VisitController';
import { Chip } from '@/components/shared/chip';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ageFromBirthdate } from '@/lib/age';
import { formatDateTime, today } from '@/lib/dates';
import { patientName } from '@/lib/format';
import type { Category } from '@/types';
import { DuplicateWarning } from './duplicate-warning';
import { PatientSearch } from './patient-search';
import { SexField } from './sex-field';
import type {
    BranchOption,
    DiagnosisOption,
    EditingVisit,
    PatientMatch,
    RankOption,
    VisitFormData,
    VisitFormField,
} from './types';
import { FIELD_ORDER, normalizeMiddleInitial, validateVisit } from './validate';

export type VisitFormProps = {
    branches: BranchOption[];
    ranks: RankOption[];
    diagnoses: DiagnosisOption[];
    categories: Category[];
    defaultCategory: Category;
    canChooseCategory: boolean;
    nextPatientNo: string;
    /** The visit to edit. Leave out to encode a new one. */
    editing: EditingVisit | null;
    /**
     * Opened from Patient Records: the Records URL (with its filters and
     * page) that Save changes and Cancel edit go back to.
     */
    returnTo?: string | null;
};

/** Element id that gets focus when a field has an error. */
const FIELD_ID: Record<VisitFormField, string> = {
    patient_id: 'encode-search',
    last_name: 'encode-last-name',
    first_name: 'encode-first-name',
    middle_initial: 'encode-mi',
    sex: 'encode-sex',
    birthdate: 'encode-birthdate',
    age: 'encode-age',
    branch_id: 'encode-branch',
    rank_id: 'encode-rank',
    rank_other: 'encode-rank-other',
    diagnosis_id: 'encode-diagnosis',
    diagnosis_other: 'encode-diagnosis-other',
    category: 'encode-category',
    remarks: 'encode-remarks',
};

function blankForm(category: Category): VisitFormData {
    return {
        patient_id: null,
        last_name: '',
        first_name: '',
        middle_initial: '',
        sex: '',
        birthdate: '',
        age: '',
        branch_id: '',
        rank_id: '',
        rank_other: '',
        diagnosis_id: '',
        diagnosis_other: '',
        category,
        remarks: '',
    };
}

function formFromVisit(visit: EditingVisit): VisitFormData {
    return {
        patient_id: visit.patient.id,
        last_name: visit.patient.last_name,
        first_name: visit.patient.first_name,
        middle_initial: visit.patient.middle_initial ?? '',
        sex: visit.patient.sex,
        birthdate: visit.patient.birthdate,
        age: String(visit.age),
        branch_id: String(visit.branch_id),
        rank_id: String(visit.rank_id),
        rank_other: visit.rank_other ?? '',
        diagnosis_id: String(visit.diagnosis_id),
        diagnosis_other: visit.diagnosis_other ?? '',
        category: visit.category,
        remarks: visit.remarks ?? '',
    };
}

/**
 * The encoding form (prototype/prototype.html, "Encode Patient"). New
 * visit: search for a returning patient first, or fill it in as a new one.
 * Edit: the same form with the visit loaded ("Editing V-…").
 *
 * Tab moves through the fields in order. Enter saves from any field;
 * in Remarks, Enter starts a new line and Ctrl+Enter saves.
 */
export function VisitForm({
    branches,
    ranks,
    diagnoses,
    categories,
    defaultCategory,
    canChooseCategory,
    nextPatientNo,
    editing,
    returnTo = null,
}: VisitFormProps) {
    const form = useForm<VisitFormData>(
        editing ? formFromVisit(editing) : blankForm(defaultCategory),
    );
    const { data } = form;

    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState<PatientMatch | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    const branch = branches.find((b) => String(b.id) === data.branch_id);
    const rankOptions = ranks.filter(
        (r) => String(r.branch_id) === data.branch_id,
    );
    const rank = rankOptions.find((r) => String(r.id) === data.rank_id);
    const diagnosis = diagnoses.find((d) => String(d.id) === data.diagnosis_id);
    const rankIsType = branch?.is_afp === false;
    const rankLabel = rankIsType ? 'Type' : 'Rank';

    // Age is counted on the visit's day: today for a new visit.
    const ageOn = editing ? editing.visited_at : new Date();
    const patient = editing?.patient ?? selected;

    function focusField(field: VisitFormField) {
        const element = document.getElementById(FIELD_ID[field]);
        element?.focus();
    }

    function focusFirstError(errors: Partial<Record<string, string>>) {
        const first = FIELD_ORDER.find((field) => errors[field]);

        if (first) {
            focusField(first);
        }
    }

    function startOver() {
        form.reset();
        form.clearErrors();
        setSelected(null);
        setQuery('');
        searchRef.current?.focus();
    }

    function changeBirthdate(value: string) {
        const age = ageFromBirthdate(value, ageOn);

        form.setData((current) => ({
            ...current,
            birthdate: value,
            age: age === null ? current.age : String(age),
        }));
    }

    function changeBranch(value: string) {
        form.setData((current) => ({
            ...current,
            branch_id: value,
            rank_id: '',
            rank_other: '',
        }));
    }

    /**
     * A returning patient: fill name, M.I., sex and birthdate, and the
     * branch and rank of their latest visit (when still on the lists).
     */
    function pickPatient(match: PatientMatch) {
        const last = match.last_visit;
        const branchOk =
            last !== null && branches.some((b) => b.id === last.branch_id);
        const rankOk =
            branchOk &&
            ranks.some(
                (r) => r.id === last.rank_id && r.branch_id === last.branch_id,
            );
        const age = ageFromBirthdate(match.birthdate);

        const next: VisitFormData = {
            ...data,
            patient_id: match.id,
            last_name: match.last_name,
            first_name: match.first_name,
            middle_initial: match.middle_initial ?? '',
            sex: match.sex,
            birthdate: match.birthdate,
            age: age === null ? '' : String(age),
            branch_id: branchOk ? String(last.branch_id) : '',
            rank_id: rankOk ? String(last.rank_id) : '',
            rank_other: rankOk ? (last.rank_other ?? '') : '',
        };

        form.setData(next);
        form.clearErrors();
        setSelected(match);
        setQuery(match.patient_no);

        // Continue at the first field still empty, usually Diagnosis.
        const empty = (['branch_id', 'rank_id', 'diagnosis_id'] as const).find(
            (field) => next[field] === '',
        );
        window.setTimeout(() => focusField(empty ?? 'diagnosis_id'));
    }

    /** Server errors: show the duplicate warning first, else the first field. */
    function onServerErrors(errors: Partial<Record<string, string>>) {
        if (errors.duplicate_patient) {
            window.setTimeout(() =>
                document.getElementById('encode-duplicate')?.focus(),
            );

            return;
        }

        focusFirstError(errors);
    }

    /**
     * @param confirmNewPatient The encoder chose "No, save as a new
     *   patient" on the duplicate warning.
     */
    function save(confirmNewPatient = false) {
        if (form.processing) {
            return;
        }

        const errors = validateVisit(data, { rank, diagnosis, rankIsType });

        form.clearErrors();

        if (Object.keys(errors).length > 0) {
            form.setError(errors as Record<VisitFormField, string>);
            focusFirstError(errors);

            return;
        }

        form.transform((values) => ({
            ...values,
            middle_initial: normalizeMiddleInitial(values.middle_initial),
            ...(confirmNewPatient ? { confirm_new_patient: true } : {}),
            ...(editing && returnTo ? { return_to: returnTo } : {}),
        }));

        if (editing) {
            form.put(VisitController.update.url(editing.id), {
                preserveScroll: true,
                onError: onServerErrors,
            });
        } else {
            form.post(VisitController.store.url(), {
                preserveScroll: true,
                onSuccess: startOver,
                onError: onServerErrors,
            });
        }
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        save();
    }

    /**
     * "Use this patient" on the duplicate warning: the visit goes to the
     * patient already on file. Their saved name, M.I., sex and birthdate
     * are used; the visit details typed so far stay.
     */
    function chooseExistingPatient(match: PatientMatch) {
        form.setData((current) => ({
            ...current,
            patient_id: match.id,
            last_name: match.last_name,
            first_name: match.first_name,
            middle_initial: match.middle_initial ?? '',
            sex: match.sex,
            birthdate: match.birthdate,
        }));
        form.clearErrors();
        setSelected(match);
        setQuery(match.patient_no);
        window.setTimeout(() =>
            document.getElementById('encode-save')?.focus(),
        );
    }

    /** Name or birthdate changed: an old duplicate warning no longer applies. */
    function setPatientField(
        field: 'last_name' | 'first_name' | 'birthdate',
        value: string,
    ) {
        if (duplicateMessage) {
            form.clearErrors('duplicate_patient' as VisitFormField);
        }

        if (field === 'birthdate') {
            changeBirthdate(value);
        } else {
            form.setData(field, value);
        }
    }

    const duplicateMessage = (form.errors as Partial<Record<string, string>>)
        .duplicate_patient;

    /** Enter saves (Ctrl+Enter in Remarks). Buttons keep their own Enter. */
    function onKeyDown(event: KeyboardEvent<HTMLFormElement>) {
        if (event.key !== 'Enter' || event.nativeEvent.isComposing) {
            return;
        }

        const target = event.target as HTMLElement;
        const withCtrl = event.ctrlKey || event.metaKey;

        if (
            target instanceof HTMLButtonElement ||
            target instanceof HTMLAnchorElement
        ) {
            return;
        }

        if (target instanceof HTMLTextAreaElement && !withCtrl) {
            return;
        }

        event.preventDefault();
        event.currentTarget.requestSubmit();
    }

    const invalid = (field: VisitFormField) =>
        form.errors[field] ? true : undefined;

    return (
        <div className="grid gap-4 p-4">
            {editing ? (
                <div className="flex flex-wrap items-center gap-2.5 rounded-md bg-warn-soft px-3 py-2 font-medium text-warn">
                    Editing {editing.visit_no} · {patientName(editing.patient)}
                    <Button asChild variant="outline" size="sm">
                        <Link href={returnTo ?? EncodeController.index.url()}>
                            Cancel edit
                        </Link>
                    </Button>
                </div>
            ) : (
                <PatientSearch
                    query={query}
                    onQueryChange={setQuery}
                    onPick={pickPatient}
                    inputRef={searchRef}
                />
            )}

            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border border-dashed px-3 py-2.5">
                <AutoValue label="Patient ID">
                    <span className="font-mono">
                        {patient?.patient_no ?? nextPatientNo}
                    </span>
                </AutoValue>
                <AutoValue label="Date & time">
                    <span className="font-mono">
                        {editing
                            ? formatDateTime(editing.visited_at)
                            : 'Set on save'}
                    </span>
                </AutoValue>
                <AutoValue label="Status">
                    {patient ? (
                        <Chip>Returning patient</Chip>
                    ) : (
                        <Chip tone="warn">New patient</Chip>
                    )}
                </AutoValue>
                {selected && !editing && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="ml-auto border-bad bg-bad-soft font-semibold text-bad hover:bg-bad hover:text-white dark:border-bad dark:bg-bad-soft dark:hover:bg-bad dark:hover:text-background"
                        onClick={startOver}
                    >
                        <X aria-hidden="true" />
                        Not this patient
                    </Button>
                )}
            </div>
            <InputError message={form.errors.patient_id} />

            <form
                onSubmit={submit}
                onKeyDown={onKeyDown}
                noValidate
                className="grid gap-4"
            >
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field
                        id={FIELD_ID.last_name}
                        label="Last name"
                        required
                        error={form.errors.last_name}
                    >
                        <Input
                            id={FIELD_ID.last_name}
                            value={data.last_name}
                            onChange={(e) =>
                                setPatientField('last_name', e.target.value)
                            }
                            maxLength={60}
                            autoComplete="off"
                            autoFocus={editing !== null}
                            aria-invalid={invalid('last_name')}
                        />
                    </Field>
                    <Field
                        id={FIELD_ID.first_name}
                        label="First name"
                        required
                        error={form.errors.first_name}
                    >
                        <Input
                            id={FIELD_ID.first_name}
                            value={data.first_name}
                            onChange={(e) =>
                                setPatientField('first_name', e.target.value)
                            }
                            maxLength={60}
                            autoComplete="off"
                            aria-invalid={invalid('first_name')}
                        />
                    </Field>
                    <Field
                        id={FIELD_ID.middle_initial}
                        label="M.I."
                        error={form.errors.middle_initial}
                    >
                        <Input
                            id={FIELD_ID.middle_initial}
                            value={data.middle_initial}
                            onChange={(e) =>
                                form.setData('middle_initial', e.target.value)
                            }
                            onBlur={(e) =>
                                form.setData(
                                    'middle_initial',
                                    normalizeMiddleInitial(e.target.value),
                                )
                            }
                            maxLength={2}
                            autoComplete="off"
                            className="uppercase"
                            aria-invalid={invalid('middle_initial')}
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="grid content-start gap-1.5">
                        <span
                            id="encode-sex-label"
                            className="text-sm leading-none font-medium"
                        >
                            Sex <RequiredMark />
                        </span>
                        <SexField
                            value={data.sex}
                            onChange={(value) => form.setData('sex', value)}
                            invalid={invalid('sex')}
                        />
                        <FieldError message={form.errors.sex} />
                    </div>
                    <Field
                        id={FIELD_ID.birthdate}
                        label="Birthdate"
                        required
                        error={form.errors.birthdate}
                    >
                        <Input
                            id={FIELD_ID.birthdate}
                            type="date"
                            value={data.birthdate}
                            max={today()}
                            onChange={(e) =>
                                setPatientField('birthdate', e.target.value)
                            }
                            aria-invalid={invalid('birthdate')}
                        />
                    </Field>
                    <Field
                        id={FIELD_ID.age}
                        label="Age"
                        required
                        error={form.errors.age}
                        hint="Auto from birthdate"
                    >
                        <Input
                            id={FIELD_ID.age}
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={120}
                            step={1}
                            value={data.age}
                            onChange={(e) =>
                                form.setData('age', e.target.value)
                            }
                            aria-invalid={invalid('age')}
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id={FIELD_ID.branch_id}
                        label="Branch of service"
                        required
                        error={form.errors.branch_id}
                    >
                        <NativeSelect
                            id={FIELD_ID.branch_id}
                            value={data.branch_id}
                            onChange={(e) => changeBranch(e.target.value)}
                            aria-invalid={invalid('branch_id')}
                        >
                            <option value="">Select branch</option>
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>
                                    {b.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field
                        id={FIELD_ID.rank_id}
                        label={rankLabel}
                        required
                        error={form.errors.rank_id ?? form.errors.rank_other}
                    >
                        <NativeSelect
                            id={FIELD_ID.rank_id}
                            value={data.rank_id}
                            onChange={(e) =>
                                form.setData('rank_id', e.target.value)
                            }
                            disabled={!branch}
                            aria-invalid={invalid('rank_id')}
                        >
                            {branch ? (
                                <>
                                    <option value="">
                                        {rankIsType
                                            ? 'Select type'
                                            : 'Select rank'}
                                    </option>
                                    {rankOptions.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            {r.name}
                                        </option>
                                    ))}
                                </>
                            ) : (
                                <option value="">Select branch first</option>
                            )}
                        </NativeSelect>
                        {rank?.is_other && (
                            <Input
                                id={FIELD_ID.rank_other}
                                value={data.rank_other}
                                onChange={(e) =>
                                    form.setData('rank_other', e.target.value)
                                }
                                placeholder="Specify"
                                maxLength={60}
                                autoComplete="off"
                                aria-label={`${rankLabel}: specify`}
                                aria-invalid={invalid('rank_other')}
                            />
                        )}
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        id={FIELD_ID.diagnosis_id}
                        label="Diagnosis"
                        required
                        error={
                            form.errors.diagnosis_id ??
                            form.errors.diagnosis_other
                        }
                    >
                        <NativeSelect
                            id={FIELD_ID.diagnosis_id}
                            value={data.diagnosis_id}
                            onChange={(e) =>
                                form.setData('diagnosis_id', e.target.value)
                            }
                            aria-invalid={invalid('diagnosis_id')}
                        >
                            <option value="">Select diagnosis</option>
                            {diagnoses.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </NativeSelect>
                        {diagnosis?.is_other && (
                            <Input
                                id={FIELD_ID.diagnosis_other}
                                value={data.diagnosis_other}
                                onChange={(e) =>
                                    form.setData(
                                        'diagnosis_other',
                                        e.target.value,
                                    )
                                }
                                placeholder="Specify diagnosis"
                                maxLength={120}
                                autoComplete="off"
                                aria-label="Diagnosis: specify"
                                aria-invalid={invalid('diagnosis_other')}
                            />
                        )}
                    </Field>
                    <Field
                        id={FIELD_ID.category}
                        label="Category"
                        error={form.errors.category}
                        hint={
                            canChooseCategory
                                ? 'ER can encode for any category.'
                                : 'Set by your office.'
                        }
                    >
                        <NativeSelect
                            id={FIELD_ID.category}
                            value={data.category}
                            onChange={(e) =>
                                form.setData(
                                    'category',
                                    e.target.value as Category,
                                )
                            }
                            disabled={!canChooseCategory}
                            aria-invalid={invalid('category')}
                        >
                            {categories.map((c) => (
                                <option key={c} value={c}>
                                    {c}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                </div>

                <Field
                    id={FIELD_ID.remarks}
                    label="Remarks"
                    error={form.errors.remarks}
                >
                    <Textarea
                        id={FIELD_ID.remarks}
                        value={data.remarks}
                        onChange={(e) =>
                            form.setData('remarks', e.target.value)
                        }
                        placeholder="Optional. e.g. From ER, admitted to ward; repeat visit"
                        maxLength={1000}
                        aria-invalid={invalid('remarks')}
                    />
                </Field>

                {duplicateMessage && !editing && (
                    <DuplicateWarning
                        message={duplicateMessage}
                        lastName={data.last_name}
                        firstName={data.first_name}
                        birthdate={data.birthdate}
                        onUse={chooseExistingPatient}
                        onSaveAnyway={() => save(true)}
                        processing={form.processing}
                    />
                )}

                <div className="flex flex-wrap items-center gap-2.5">
                    <Button
                        id="encode-save"
                        type="submit"
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        {editing ? 'Save changes' : 'Save & New'}
                    </Button>
                    {!editing && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={startOver}
                            disabled={form.processing}
                        >
                            Clear
                        </Button>
                    )}
                    <span className="text-xs text-muted-foreground">
                        Tab moves between fields. Enter saves (Ctrl+Enter in
                        Remarks).
                    </span>
                </div>
            </form>
        </div>
    );
}

function RequiredMark() {
    return (
        <span className="text-bad" aria-hidden="true">
            *
        </span>
    );
}

function FieldError({ message }: { message?: string }) {
    return <InputError message={message} className="text-xs text-bad" />;
}

function Field({
    id,
    label,
    required,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <Label htmlFor={id}>
                {label}
                {required && (
                    <>
                        {' '}
                        <RequiredMark />
                        <span className="sr-only">(required)</span>
                    </>
                )}
            </Label>
            {children}
            <FieldError message={error} />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

function AutoValue({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{label}</span>
            <span className="text-sm font-medium">{children}</span>
        </div>
    );
}
