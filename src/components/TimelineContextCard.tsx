import React from 'react';
import TimelineNodeShell from './TimelineNodeShell';

export default function TimelineContextCard({
  label,
  detail,
  whyText,
}: {
  label: string;
  detail?: string;
  whyText: string;
}) {
  return (
    <TimelineNodeShell
      layerLabel="Context"
      label={label}
      summary={detail}
      whyText={whyText}
      hoverTitle={whyText}
      borderClass="border-violet-500/20 bg-violet-500/5"
      labelClass="text-violet-300/80"
    />
  );
}
