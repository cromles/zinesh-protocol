import React from 'react';
import TimelineNodeShell from './TimelineNodeShell';

export default function TimelineObservationCard({
  label,
  detail,
  description,
  whyText,
}: {
  label: string;
  detail?: string;
  description?: string;
  whyText: string;
}) {
  return (
    <TimelineNodeShell
      layerLabel="Risk Observation"
      label={label}
      summary={detail || description}
      whyText={whyText}
      hoverTitle={whyText}
      borderClass="border-amber-500/25 bg-amber-500/10"
      labelClass="text-amber-300/90"
    />
  );
}
