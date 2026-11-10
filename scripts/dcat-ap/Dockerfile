FROM python:3.13.3-slim-bookworm@sha256:56a11364ffe0fee3bd60af6d6d5209eba8a99c2c16dc4c7c5861dc06261503cc
RUN apt-get update && apt-get install -y --no-install-recommends openjdk-17-jre-headless curl ca-certificates && rm -rf /var/lib/apt/lists/*
COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt
RUN curl -fsSL https://archive.apache.org/dist/jena/binaries/apache-jena-5.4.0.tar.gz -o /tmp/jena.tar.gz \
    && echo '0d1d13dd8bb098b28996ffd9871f3a42f54984ac276c10dab0c0d2ccd8c66d69737f20bc3fc05f1ee3a2ceeacba318ef5f1a1f4fd2cba19041210675fac245ed  /tmp/jena.tar.gz' | sha512sum -c - \
    && tar -xzf /tmp/jena.tar.gz -C /opt && rm /tmp/jena.tar.gz
ENV PATH="/opt/apache-jena-5.4.0/bin:${PATH}"
WORKDIR /workspace
ENTRYPOINT ["python", "scripts/dcat-ap/validate.py"]
