import React from 'react';
import TimelineNodeShell from './TimelineNodeShell';

export default function TimelineSignalCard({
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
      layerLabel="Signal"
      label={label}
      summary={detail}
      whyText={whyText}
      hoverTitle={whyText}
      borderClass="border-emerald-500/20 bg-emerald-500/5"
      labelClass="text-emerald-300/80"
    />
  );
}
