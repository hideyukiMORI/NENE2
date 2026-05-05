export type HealthResponse = {
  readonly status: string;
  readonly service: string;
};

const defaultApiBaseUrl = '/api';

export async function fetchHealth(
  apiBaseUrl: string = import.meta.env.VITE_NENE2_API_BASE_URL ??
    defaultApiBaseUrl,
): Promise<HealthResponse> {
  const response = await fetch(`${apiBaseUrl}/health`, {
    headers: {
      Accept: 'application/json',
    },
  });

  if (!response.ok) {
    throw new Error(`Health check failed with HTTP ${response.status}.`);
  }

  const payload: unknown = await response.json();

  if (!isHealthResponse(payload)) {
    throw new Error('Health check response did not match the expected shape.');
  }

  return payload;
}

function isHealthResponse(value: unknown): value is HealthResponse {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const candidate = value as Record<string, unknown>;

  return (
    typeof candidate.status === 'string' &&
    typeof candidate.service === 'string'
  );
}
