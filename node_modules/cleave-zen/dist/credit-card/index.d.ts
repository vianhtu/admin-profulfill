import type { DelimiterType } from '../common/types';
import { CreditCardType } from './constants';
import type { FormatCreditCardOptions } from './types';
export declare const formatCreditCard: (value: string, options?: FormatCreditCardOptions) => string;
export declare const getCreditCardType: (value: string, delimiter?: DelimiterType) => CreditCardType;
export declare const unformatCreditCard: (value: string) => string;
