import type { GabineteModuleCode } from '@/types';

export function hasModule(
    modules: readonly GabineteModuleCode[],
    module: GabineteModuleCode,
): boolean {
    return modules.includes(module);
}
