FROM docker-registry.wikimedia.org/nodejs14-devel

RUN apt-get update && \
	apt-get install -y \
		ca-certificates \
		git

ARG UID=1000
ARG GID=1000

RUN addgroup --gid $GID runuser && adduser --uid $UID --gid $GID --disabled-password --gecos "" runuser

RUN npm install --global npm@7.21.0

USER runuser
