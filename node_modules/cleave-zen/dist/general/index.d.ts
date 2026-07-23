import type { FormatGeneralOptions } from './types';
export declare const formatGeneral: (value: string, options: FormatGeneralOptions) => string;
export declare const unformatGeneral: (value: string, options: Pick<FormatGeneralOptions, 'delimiter' | 'delimiters'>) => string;
