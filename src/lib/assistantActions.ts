export type AssistantAction =
  | 'open_deposit'
  | 'open_founder_panel'
  | 'open_dashboard'
  | 'open_login';

export interface AssistantSuggestion {
  label: string;
  action: AssistantAction;
}

export const ASSISTANT_ACTION_EVENT = 'zinesh-assistant-action';

export function dispatchAssistantAction(action: AssistantAction): void {
  window.dispatchEvent(new CustomEvent(ASSISTANT_ACTION_EVENT, { detail: { action } }));
}
