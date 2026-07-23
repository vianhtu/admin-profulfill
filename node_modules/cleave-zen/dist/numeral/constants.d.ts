import type { DelimiterType } from '../common/types';
export declare enum NumeralThousandGroupStyles {
    THOUSAND = "thousand",
    LAKH = "lakh",
    WAN = "wan",
    NONE = "none"
}
export declare const DefaultNumeralDelimiter: DelimiterType;
export declare const DefaultNumeralDecimalMark: DelimiterType;
export declare const DefaultNumeralThousandGroupStyle: NumeralThousandGroupStyles;
export declare const DefaultNumeralDecimalScale: number;
export declare const DefaultNumeralIntegerScale: number;
