import type { Sex } from '@/types';

const OPTIONS: Sex[] = ['Male', 'Female'];

type SexFieldProps = {
    value: Sex | '';
    onChange: (value: Sex) => void;
    invalid?: boolean;
};

/**
 * Male / Female as two joined buttons (the prototype's segmented control).
 * They are real radio buttons: Tab reaches the group, arrow keys switch.
 */
export function SexField({ value, onChange, invalid }: SexFieldProps) {
    return (
        <div
            role="radiogroup"
            aria-labelledby="encode-sex-label"
            aria-invalid={invalid || undefined}
            className="flex h-9 overflow-hidden rounded-md border border-input shadow-xs aria-invalid:border-destructive"
        >
            {OPTIONS.map((option) => (
                <label
                    key={option}
                    className="flex flex-1 cursor-pointer items-center justify-center text-sm font-medium not-first:border-l not-first:border-input has-checked:bg-navy-soft has-checked:font-bold has-checked:text-navy has-focus-visible:outline-2 has-focus-visible:-outline-offset-2 has-focus-visible:outline-ring"
                >
                    <input
                        type="radio"
                        name="sex"
                        id={option === OPTIONS[0] ? 'encode-sex' : undefined}
                        value={option}
                        checked={value === option}
                        onChange={() => onChange(option)}
                        className="sr-only"
                    />
                    {option}
                </label>
            ))}
        </div>
    );
}
