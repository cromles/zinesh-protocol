import { getSessionToken } from './auth';
import type { AssistantSuggestion } from './assistantActions';
import { apiUrl } from './apiBase';

export type AiChatRole = 'user' | 'assistant';

export interface AiChatMessage {
  role: AiChatRole;
  content: string;
}

export interface AiChatResponse {
  reply: string;
  isFounder: boolean;
  loggedIn: boolean;
  source: 'live' | 'local' | string;
  suggestions: AssistantSuggestion[];
}

const AI_CHAT_API = apiUrl('/api/ai_chat.php');

export async function sendAiChatMessage(
  messages: AiChatMessage[],
  sessionTokenOverride?: string | null,
): Promise<AiChatResponse> {
  const sessionToken = (sessionTokenOverride ?? getSessionToken())?.trim();
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  if (sessionToken) {
    headers['Authorization'] = `Bearer ${sessionToken}`;
    headers['X-Session-Token'] = sessionToken;
  }

  const body: Record<string, unknown> = { messages };
  if (sessionToken) {
    body.sessionToken = sessionToken;
  }

  const res = await fetch(AI_CHAT_API, {
    method: 'POST',
    credentials: 'include',
    headers,
    body: JSON.stringify(body),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.ok) {
    throw new Error((data.message as string) || 'Asistan yanıt veremedi.');
  }

  const suggestions = Array.isArray(data.suggestions)
    ? (data.suggestions as AssistantSuggestion[]).filter(
        (s) => s && typeof s.label === 'string' && typeof s.action === 'string',
      )
    : [];

  return {
    reply: String(data.reply ?? ''),
    isFounder: Boolean(data.isFounder),
    loggedIn: Boolean(data.loggedIn),
    source: String(data.source ?? 'local'),
    suggestions,
  };
}
