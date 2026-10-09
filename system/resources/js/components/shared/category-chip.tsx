import { Chip } from '@/components/shared/chip';
import type { ChipTone } from '@/components/shared/chip';
import type { Category } from '@/types';

/** Same colors as the prototype: OPD green, ER red, Admission navy. */
const CATEGORY_TONE: Record<Category, ChipTone> = {
    OPD: 'ok',
    ER: 'bad',
    Admission: 'navy',
};

type CategoryChipProps = {
    category: Category;
    className?: string;
};

/** Colored label for a visit category: OPD, ER or Admission. */
export function CategoryChip({ category, className }: CategoryChipProps) {
    return (
        <Chip tone={CATEGORY_TONE[category]} className={className}>
            {category}
        </Chip>
    );
}
