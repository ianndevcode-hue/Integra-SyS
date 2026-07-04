import 'reflect-metadata';
import { NestFactory } from '@nestjs/core';
import { ValidationPipe } from '@nestjs/common';
import { getApiConfig } from '@integra/config';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create(AppModule);
  app.setGlobalPrefix('api/v1');
  app.enableCors();
  app.useGlobalPipes(new ValidationPipe({ transform: true }));

  const { port } = getApiConfig();
  await app.listen(port);
  console.log(`Integra SYS API rodando em http://localhost:${port}/api/v1`);
}

bootstrap();
