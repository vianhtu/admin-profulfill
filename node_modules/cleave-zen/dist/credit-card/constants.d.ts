import type { DelimiterType } from '../common/types';
import type { CreditCardBlocksType, CreditCardRegexType } from './types';
export declare const DefaultCreditCardDelimiter: DelimiterType;
export declare enum CreditCardType {
    UATP = "uatp",
    AMEX = "amex",
    DINERS = "diners",
    DISCOVER = "discover",
    MASTERCARD = "mastercard",
    DANKORT = "dankort",
    INSTAPAYMENT = "instapayment",
    JCB15 = "jcb15",
    JCB = "jcb",
    MAESTRO = "maestro",
    VISA = "visa",
    MIR = "mir",
    UNIONPAY = "unionpay",
    GENERAL = "general"
}
export declare const CreditCardBlocks: CreditCardBlocksType;
export declare const CreditCardRegex: CreditCardRegexType;
