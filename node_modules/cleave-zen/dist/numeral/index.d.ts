import type { FormatNumeralOptions } from './types';
export declare const formatNumeral: (value: string, options?: FormatNumeralOptions) => string;
export declare const unformatNumeral: (value: string, options?: Pick<FormatNumeralOptions, 'numeralDecimalMark'>) => string;
