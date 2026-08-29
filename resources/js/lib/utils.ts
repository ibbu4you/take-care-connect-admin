import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Compose class names, letting later Tailwind utilities win over earlier ones.
 *
 * Plain clsx would emit `px-2 px-4` and leave the browser to pick by source
 * order, which makes a component's `className` prop unreliable as an override.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
