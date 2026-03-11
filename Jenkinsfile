pipeline {
    agent any

    environment {
        IMAGE_NAME = "php-webapp-image"
        CONTAINER_NAME = "php-webapp-container"
        HOST_PORT = "8085"
        CONTAINER_PORT = "80"
    }

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Verify Files') {
            steps {
                sh '''
                    echo "Current path:"
                    pwd
                    echo "Files:"
                    ls -ltra
                    test -f Dockerfile
                '''
            }
        }

        stage('Build Docker Image') {
            steps {
                sh '''
                    docker build -t ${IMAGE_NAME}:${BUILD_NUMBER} .
                    docker tag ${IMAGE_NAME}:${BUILD_NUMBER} ${IMAGE_NAME}:latest
                '''
            }
        }

        stage('Stop Old Container') {
            steps {
                sh '''
                    docker rm -f ${CONTAINER_NAME} || true
                '''
            }
        }

        stage('Run New Container') {
            steps {
                sh '''
                    docker run -d \
                      --name ${CONTAINER_NAME} \
                      -p ${HOST_PORT}:${CONTAINER_PORT} \
                      ${IMAGE_NAME}:latest
                '''
            }
        }

        stage('Validate Deployment') {
            steps {
                sh '''
                    sleep 10
                    docker ps
                    curl -I http://localhost:${HOST_PORT}
                '''
            }
        }
    }

    post {
        success {
            echo "Deployment successful"
            echo "App URL: http://<jenkins-server-ip>:8085"
        }
        failure {
            echo "Deployment failed"
        }
    }
}
