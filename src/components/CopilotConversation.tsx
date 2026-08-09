import React from 'react';
import CopilotMessage from './CopilotMessage';
import type { CopilotConversationMessage } from '../lib/copilotApi';

export default function CopilotConversation({
  messages,
}: {
  messages: CopilotConversationMessage[];
}) {
  if (messages.length === 0) {
    return (
      <p className="text-xs text-zinc-600 leading-relaxed">
        Hazır sorulardan birini seçerek Risk Engine paketinin açıklamasını isteyebilirsiniz.
      </p>
    );
  }

  return (
    <div className="space-y-3 max-h-80 overflow-y-auto pr-1">
      {messages.map((message) => (
        <CopilotMessage
          key={message.id}
          role={message.role}
          text={message.text}
          chain={message.role === 'copilot' ? message.explainabilityChain : undefined}
        />
      ))}
    </div>
  );
}
