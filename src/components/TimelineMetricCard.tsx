import React from 'react';
import TimelineNodeShell from './TimelineNodeShell';

export default function TimelineMetricCard({
  label,
  detail,
}: {
  label: string;
  detail?: string;
}) {
  const whyText = detail != null ? `${label}: ${detail}` : label;
  return (
    <TimelineNodeShell
      layerLabel="Metric"
      label={label}
      summary={detail}
      whyText={whyText}
      hoverTitle={whyText}
      borderClass="border-zinc-700 bg-zinc-900/80"
      labelClass="text-zinc-500"
    />
  );
}
