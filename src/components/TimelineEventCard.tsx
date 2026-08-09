import React from 'react';
import TimelineNodeShell from './TimelineNodeShell';

export default function TimelineEventCard({
  label,
  detail,
  timestamp,
  whyText,
}: {
  label: string;
  detail?: string;
  timestamp?: string;
  whyText: string;
}) {
  const summary = [detail && detail !== label ? detail : null, timestamp].filter(Boolean).join(' · ');
  return (
    <TimelineNodeShell
      layerLabel="Event"
      label={label}
      summary={summary || undefined}
      whyText={whyText}
      hoverTitle={whyText}
      borderClass="border-sky-500/20 bg-sky-500/5"
      labelClass="text-sky-300/80"
    />
  );
}
