import type { StripDelimitersProps, GetFormattedValueProps, BlocksType } from './types';
export declare const isString: (value: any) => value is string;
export declare const stripNonNumeric: (value: string) => string;
export declare const getMaxLength: (blocks: BlocksType) => number;
export declare const headStr: (str: string, length: number) => string;
export declare const getDelimiterRegexByDelimiter: (delimiter: string) => RegExp;
export declare const stripDelimiters: ({ value, delimiters, }: StripDelimitersProps) => string;
export declare const getFormattedValue: ({ value, blocks, delimiter, delimiters, delimiterLazyShow, }: GetFormattedValueProps) => string;
