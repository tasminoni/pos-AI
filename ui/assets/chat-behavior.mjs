export const questionBytes = value => new TextEncoder().encode(value).length;
export const nearBottom = element => element.scrollHeight - element.scrollTop - element.clientHeight < 100;
