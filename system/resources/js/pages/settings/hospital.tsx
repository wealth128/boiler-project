import { Head, router, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import type { FormEvent } from 'react';
import HospitalController from '@/actions/App/Http/Controllers/Settings/HospitalController';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PictureField, usePickedUrl } from '@/features/hospital/picture-field';
import { ReportPreview } from '@/features/hospital/report-preview';
import type {
    HospitalDefaults,
    HospitalValues,
    LogoLimits,
} from '@/features/hospital/types';

type Props = {
    values: HospitalValues;
    defaults: HospitalDefaults;
    /** Addresses of the saved pictures, or null. */
    logo_url: string | null;
    prepared_signature_url: string | null;
    noted_signature_url: string | null;
    limits: LogoLimits;
};

type Picture = 'logo' | 'prepared_signature' | 'noted_signature';

type FormData = HospitalValues & Record<Picture, File | null>;

/**
 * Hospital settings (Admin only): name, office line, report signatories (with
 * signature pictures) and logo. One Save button. Under the form, a live
 * preview of the report header and signature block, built from the same
 * components as the report.
 */
export default function HospitalSettings({
    values,
    defaults,
    logo_url,
    prepared_signature_url,
    noted_signature_url,
    limits,
}: Props) {
    const form = useForm<FormData>({
        ...values,
        logo: null,
        prepared_signature: null,
        noted_signature: null,
    });
    const inputs = {
        logo: useRef<HTMLInputElement>(null),
        prepared_signature: useRef<HTMLInputElement>(null),
        noted_signature: useRef<HTMLInputElement>(null),
    };

    // A picture just chosen shows in the preview before it is saved.
    const picked = {
        logo: usePickedUrl(form.data.logo),
        prepared_signature: usePickedUrl(form.data.prepared_signature),
        noted_signature: usePickedUrl(form.data.noted_signature),
    };

    function pick(picture: Picture, file: File | null, problem: string | null) {
        form.clearErrors(picture);

        if (problem) {
            form.setError(picture, problem);
        }

        form.setData(picture, file);
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(HospitalController.update.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData((data) => ({
                    ...data,
                    logo: null,
                    prepared_signature: null,
                    noted_signature: null,
                }));

                Object.values(inputs).forEach((input) => {
                    if (input.current) {
                        input.current.value = '';
                    }
                });
            },
        });
    }

    function remove(url: string) {
        router.delete(url, { preserveScroll: true, preserveState: true });
    }

    const text = (key: keyof HospitalValues) => ({
        id: key,
        name: key,
        value: form.data[key],
        onChange: (event: { target: { value: string } }) =>
            form.setData(key, event.target.value),
    });

    return (
        <>
            <Head title="Hospital settings" />

            <PageHeader
                title="Hospital settings"
                description="Shown on every report: screen, Print, PDF and Excel. Admin only."
            />

            <form onSubmit={submit} className="space-y-4" noValidate>
                <section className="rounded-lg border bg-card">
                    <div className="border-b px-4 py-3">
                        <h2 className="text-base font-semibold">
                            Report header
                        </h2>
                    </div>
                    <div className="grid gap-4 p-4 md:grid-cols-2">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="hospital_name">Hospital name</Label>
                            <Input
                                {...text('hospital_name')}
                                required
                                minLength={2}
                                maxLength={150}
                                autoComplete="off"
                            />
                            <InputError message={form.errors.hospital_name} />
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="report_office">Office line</Label>
                            <Input
                                {...text('report_office')}
                                maxLength={150}
                                autoComplete="off"
                                placeholder={defaults.report_office}
                            />
                            <InputError message={form.errors.report_office} />
                        </div>
                        <div className="md:col-span-2">
                            <PictureField
                                id="logo"
                                noun="logo"
                                cropKind="logo"
                                ready={form.data.logo !== null}
                                label="Logo"
                                currentUrl={logo_url}
                                limits={limits}
                                error={form.errors.logo}
                                inputRef={inputs.logo}
                                onPick={(file, problem) =>
                                    pick('logo', file, problem)
                                }
                                onRemove={() =>
                                    remove(
                                        HospitalController.destroyLogo.url(),
                                    )
                                }
                                removeLabel="Remove logo"
                            />
                        </div>
                    </div>
                </section>

                <section className="rounded-lg border bg-card">
                    <div className="border-b px-4 py-3">
                        <h2 className="text-base font-semibold">Signatures</h2>
                    </div>
                    <div className="grid gap-6 p-4 lg:grid-cols-2">
                        <fieldset className="grid content-start gap-4">
                            <legend className="mb-1 text-sm font-semibold">
                                Prepared by
                            </legend>
                            <div className="grid gap-2">
                                <Label htmlFor="prepared_by">Name</Label>
                                <Input
                                    {...text('prepared_by')}
                                    maxLength={100}
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.prepared_by} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="prepared_by_title">Title</Label>
                                <Input
                                    {...text('prepared_by_title')}
                                    maxLength={100}
                                    autoComplete="off"
                                    placeholder={defaults.prepared_by_title}
                                />
                                <InputError
                                    message={form.errors.prepared_by_title}
                                />
                            </div>
                            <PictureField
                                id="prepared_signature"
                                cropKind="signature"
                                ready={form.data.prepared_signature !== null}
                                noun="signature"
                                label="Signature picture"
                                currentUrl={prepared_signature_url}
                                limits={limits}
                                error={form.errors.prepared_signature}
                                inputRef={inputs.prepared_signature}
                                onPick={(file, problem) =>
                                    pick('prepared_signature', file, problem)
                                }
                                onRemove={() =>
                                    remove(
                                        HospitalController.destroySignature.url(
                                            { who: 'prepared' },
                                        ),
                                    )
                                }
                                removeLabel="Remove signature"
                            />
                        </fieldset>

                        <fieldset className="grid content-start gap-4">
                            <legend className="mb-1 text-sm font-semibold">
                                Noted by
                            </legend>
                            <div className="grid gap-2">
                                <Label htmlFor="noted_by">Name</Label>
                                <Input
                                    {...text('noted_by')}
                                    maxLength={100}
                                    autoComplete="off"
                                />
                                <InputError message={form.errors.noted_by} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="noted_by_title">Title</Label>
                                <Input
                                    {...text('noted_by_title')}
                                    maxLength={100}
                                    autoComplete="off"
                                    placeholder={defaults.noted_by_title}
                                />
                                <InputError
                                    message={form.errors.noted_by_title}
                                />
                            </div>
                            <PictureField
                                id="noted_signature"
                                cropKind="signature"
                                ready={form.data.noted_signature !== null}
                                noun="signature"
                                label="Signature picture"
                                currentUrl={noted_signature_url}
                                limits={limits}
                                error={form.errors.noted_signature}
                                inputRef={inputs.noted_signature}
                                onPick={(file, problem) =>
                                    pick('noted_signature', file, problem)
                                }
                                onRemove={() =>
                                    remove(
                                        HospitalController.destroySignature.url(
                                            { who: 'noted' },
                                        ),
                                    )
                                }
                                removeLabel="Remove signature"
                            />
                        </fieldset>
                    </div>
                </section>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        Save settings
                    </Button>
                </div>
            </form>

            <section className="mt-6" aria-labelledby="preview-title">
                <h2 id="preview-title" className="mb-1 text-base font-semibold">
                    Preview
                </h2>
                <p className="mb-3 text-sm text-muted-foreground">
                    How the report header and signatures will look, with what
                    you typed. The title and month are a sample.
                </p>
                <ReportPreview
                    values={form.data}
                    defaults={defaults}
                    logoUrl={picked.logo ?? logo_url}
                    preparedSignatureUrl={
                        picked.prepared_signature ?? prepared_signature_url
                    }
                    notedSignatureUrl={
                        picked.noted_signature ?? noted_signature_url
                    }
                />
            </section>
        </>
    );
}
