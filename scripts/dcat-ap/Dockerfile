FROM python:3.13.18-slim-trixie@sha256:168d3a5d10f2152f8e8d77b6843ac82c22a846cfefeef7afdf9aa630404e82a7
RUN apt-get update && apt-get install -y --no-install-recommends openjdk-21-jre-headless curl ca-certificates && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://archive.apache.org/dist/jena/binaries/apache-jena-6.3.0.tar.gz -o /tmp/jena.tar.gz \
    && echo '828ac4bc3b377eacbadd888de5558039688a28eb5357fc741c2eb833582447f53455f75857bd1c832114c9d58beb671d581bad84ea5f0f31d3aff74fe805f0ce  /tmp/jena.tar.gz' | sha512sum -c - \
    && tar -xzf /tmp/jena.tar.gz -C /opt && rm /tmp/jena.tar.gz
ENV PATH="/opt/apache-jena-6.3.0/bin:${PATH}"
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && pip check
WORKDIR /workspace
ENTRYPOINT ["python", "scripts/dcat-ap/validate.py"]
