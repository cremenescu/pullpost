# SPDX-License-Identifier: GPL-2.0-or-later
# The download engine: Python (standard library only) and Debian's Chromium. It writes into the data
# volume it shares with the web container, as the same user (www-data, 33).
FROM python:3.12-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends chromium fonts-dejavu-core ca-certificates tzdata \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /data \
 && chown www-data:www-data /data

COPY --chmod=0644 igfetch.py server.py /srv/

ENV HOME=/tmp \
    CHROME=/usr/bin/chromium \
    CHROME_NO_SANDBOX=1 \
    DATA=/data \
    BIND=0.0.0.0 \
    PORT=8898 \
    PYTHONUNBUFFERED=1

USER www-data
EXPOSE 8898
CMD ["python3", "-I", "/srv/server.py"]
